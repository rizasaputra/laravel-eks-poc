<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class S3Controller extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $s3 = \App::make('aws')->createClient('s3');
        $buckets = $s3->listBuckets([]);
        
        foreach ($buckets['Buckets'] as $bucket) {
            $result[] = $bucket;
        }
        
        return response()->json($result);
    }
}
