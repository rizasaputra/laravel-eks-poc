## About

Simple example running Laravel on AWS Elastic Kubernetes Service using Kubernetes 1.19. 

The app has a sample API endpoint `/api/s3` to list all S3 bucket using [AWS Service Provider for Laravel](https://github.com/aws/aws-sdk-php-laravel). The app is deployed to AWS region `ap-southeast-1` to an EKS cluster named `laravel-eks-poc`. If you are going to use other region or cluster name, change the steps and files in this example accordingly.

This sample is made for Proof of Concept and learning purpose and is not meant to be deployed to production as is.

## Steps

### 0. Prerequisite
Install [eksctl](https://eksctl.io/), [kubectl](https://docs.aws.amazon.com/eks/latest/userguide/install-kubectl.html), [helm](https://helm.sh/docs/intro/install/), [aws-cli](https://docs.aws.amazon.com/cli/latest/userguide/install-cliv2.html).

### 1. Create EKS cluster
Create the cluster. This process will take around 15 minutes.

`eksctl create cluster -f kube/cluster.yaml`

Verify the cluster is up and running. If we see our node, than the cluster is up.

`kubectl get nodes`

### 2. Prepare Docker image

We will bake the source code to a Docker image and push it to AWS Elastic Container Repository for deployment.

```
aws ecr get-login-password --region ap-southeast-1 | docker login --username AWS --password-stdin <AWS_ACCOUNT_ID>.dkr.ecr.ap-southeast-1.amazonaws.com
docker build -t laravel .
docker tag laravel:latest <AWS_ACCOUNT_ID>.dkr.ecr.ap-southeast-1.amazonaws.com/laravel:latest
docker push <AWS_ACCOUNT_ID>.dkr.ecr.ap-southeast-1.amazonaws.com/laravel:latest
```

### 3. Associate IAM role with Service Account

Create an OIDC provider to associate IAM Identity Provider with EKS ClusterConfig. This is necessary for a lot of deployments that we will do later in these steps, such as deploying cluster autoscaler and application load balancer controller.

`eksctl utils associate-iam-oidc-provider --region ap-southeast-1 --cluster laravel-eks-poc --approve`

Verify the Identity Provider has been registered in [IAM Console](https://console.aws.amazon.com/iamv2/home?#/identity_providers).

Create an IAM role bound to a service account with read-only access to S3 using AWS managed policy AmazonS3ReadOnlyAccess. We will use this service account for our Laravel app deployment to enable the API endpoint that lists the S3 bucket.

```
eksctl create iamserviceaccount \
  --region ap-southeast-1 \
  --name laravel-kube-sa \
  --namespace default \
  --cluster laravel-eks-poc \
  --attach-policy-arn arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess \
  --approve \
  --override-existing-serviceaccounts
```

Verify the service account creation. Note the Annotations part.

`kubectl describe sa laravel-kube-sa`

Verify the role creation is success in [IAM Console](https://console.aws.amazon.com/iam/home#/roles). The role name will have value from Annotations from previous part.

### 4. Deploy the app and expose the service

Open `kube/service.yaml` and change the image address, and then deploy the app.

`kubectl apply -f kube/service.yaml`

Verify the pods and the service.

```
kubectl get pods
kubectl get service
```

### 5. Setup pods auto scaling

Deploy metrics-server that will drive the scaling behavior of the deployment.

`kubectl apply -f https://github.com/kubernetes-sigs/metrics-server/releases/latest/download/components.yaml`

Verify the installation.

`kubectl get deployment metrics-server -n kube-system`

Install Horizontal Pod Autoscaler (HPA) to scale out the number of pods in a deployment, replication controller, or replica set based on that resource's CPU utilization.

`kubectl autoscale deployment laravel --cpu-percent=60 --min=1 --max=10`

Verify the installation.

`kubectl describe hpa`

Wait a few minutes. The number of pods should be reduced to 1 due to low CPU utilization.

`kubectl get pods`

### 6. Setup worker node auto scaling using [Kubernetes Autoscaler](https://github.com/kubernetes/autoscaler)

Prepare an IAM service account to be used by cluster autoscaler.

```
aws iam create-policy   \
  --policy-name AmazonEKSClusterAutoscalerPolicy \
  --policy-document file://kube/kube-asg-policy.json
  
eksctl create iamserviceaccount \
  --region=ap-southeast-1 \
  --cluster=laravel-eks-poc \
  --namespace=kube-system \
  --name=cluster-autoscaler \
  --attach-policy-arn=arn:aws:iam::<AWS_ACCOUNT_ID>:policy/AmazonEKSClusterAutoscalerPolicy \
  --override-existing-serviceaccounts \
  --approve
```

Take a look at cluster autoscaler config file at `kube/cluster-autoscaler.yaml`. This file is based on [example file](https://github.com/kubernetes/autoscaler/blob/master/cluster-autoscaler/cloudprovider/aws/examples/cluster-autoscaler-autodiscover.yaml) on cluster autoscaler page with following changes:

- Service Account declaration is commented out since we already made the Service Account
- We annotate the deployment with `cluster-autoscaler.kubernetes.io/safe-to-evict: 'false'` to prevent cluster autoscaler from removing node where its own pod is running
- We add some additional container commands
- We patch the deployment image to match Kubernetes version. You can get the release version from [release page](https://api.github.com/repos/kubernetes/autoscaler/releases)

Deploy cluster autoscaler.

`kubectl apply -f kube/cluster-autoscaler.yaml`

Verify the cluster autoscaler pod is running.

`kubectl get pods -n kube-system`

Let's test the cluster autoscaler function. Increase the replica for `laravel` deployment.

`kubectl scale --replicas=10 deployment/laravel`

Wait a few minutes and check the number of nodes to verify the worker node scaled out.

```
kubectl get nodes
kubectl get pods -o wide
```

### 7. Expose the service using [AWS Load Balancer Controller](https://github.com/aws/eks-charts/tree/master/stable/aws-load-balancer-controller)

Prepare an IAM service account to be used by the controller.

```
curl -o iam-policy.json https://raw.githubusercontent.com/kubernetes-sigs/aws-load-balancer-controller/main/docs/install/iam_policy.json

aws iam create-policy \
  --policy-name AWSLoadBalancerControllerIAMPolicy \
  --policy-document file://iam-policy.json
    
eksctl create iamserviceaccount \
  --region=ap-southeast-1 \
  --cluster=laravel-eks-poc \
  --namespace=kube-system \
  --name=aws-load-balancer-controller \
  --attach-policy-arn=arn:aws:iam::<AWS_ACCOUNT_ID>:policy/AWSLoadBalancerControllerIAMPolicy \
  --approve \
  --override-existing-serviceaccounts
```

Add the EKS repository to Helm.

`helm repo add eks https://aws.github.io/eks-charts`

Install TargetGroupBinding CRDs.

`kubectl apply -k "github.com/aws/eks-charts/stable/aws-load-balancer-controller/crds?ref=master"`

Install AWS Load Balancer Controller.

```
helm upgrade -i aws-load-balancer-controller \
  eks/aws-load-balancer-controller \
  -n kube-system \
  --set clusterName=laravel-eks-poc \
  --set serviceAccount.create=false \
  --set serviceAccount.name=aws-load-balancer-controller
```

Verify the installation is successful.

`kubectl -n kube-system rollout status deployment aws-load-balancer-controller`

Deploy the ingress.

`kubectl apply -f kube/ingress.yaml`

Get load balancer DNS.

`kubectl get ingress ingress-laravel`

Verify everything works properly by hitting the custom endpoint to list S3 bucket.

`curl https://<ALB_ADDRESS>/api/s3`

### 8. Enable monitoring and logging

Enable container insight on Cloudwatch by running this commands:

```
ClusterName=laravel-eks-poc
RegionName=ap-southeast-1
FluentBitHttpPort='2020'
FluentBitReadFromHead='Off'
[[ ${FluentBitReadFromHead} = 'On' ]] && FluentBitReadFromTail='Off'|| FluentBitReadFromTail='On'
[[ -z ${FluentBitHttpPort} ]] && FluentBitHttpServer='Off' || FluentBitHttpServer='On'
curl https://raw.githubusercontent.com/aws-samples/amazon-cloudwatch-container-insights/latest/k8s-deployment-manifest-templates/deployment-mode/daemonset/container-insights-monitoring/quickstart/cwagent-fluent-bit-quickstart.yaml | sed 's/{{cluster_name}}/'${ClusterName}'/;s/{{region_name}}/'${RegionName}'/;s/{{http_server_toggle}}/"'${FluentBitHttpServer}'"/;s/{{http_server_port}}/"'${FluentBitHttpPort}'"/;s/{{read_from_head}}/"'${FluentBitReadFromHead}'"/;s/{{read_from_tail}}/"'${FluentBitReadFromTail}'"/' | kubectl apply -f - 
```

Check [Container Insights](https://ap-southeast-1.console.aws.amazon.com/cloudwatch/home?region=ap-southeast-1#container-insights:infrastructure) to see
your cluster performance and [Cloudwatch Log Group](https://ap-southeast-1.console.aws.amazon.com/cloudwatch/home?region=ap-southeast-1#logsV2:log-groups) to see application log.

Alternatively, to reduce cost, setup [Cloudwatch Agent](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/Container-Insights-setup-metrics.html) and [FluentBit](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/Container-Insights-setup-logs-FluentBit.html#ContainerInsights-fluentbit-volume) manually and configure the options.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).