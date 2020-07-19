<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use App\Http\Controllers\Controller;

use App\Models\Cronjobs;
use App\Models\Websites;
use App\Models\User;
use Spatie\Tags\Tag;

use App\Repositories\Interfaces\CronjobRepositoryInterface;
use App\Repositories\Interfaces\TagsRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;

class CronJobsController extends Controller
{
    private $cronjobRepository;
    private $userRepository;
    private $tagRepository;

    public function __construct(CronjobRepositoryInterface $cronjobRepository, TagsRepositoryInterface $tagRepository, UserRepositoryInterface $userRepository)
    {
        $this->cronjobRepository = $cronjobRepository;
        $this->userRepository = $userRepository;
        $this->tagRepository = $tagRepository;

    }

    //Get all cronjobs added and display on cronjobs dashboard
    public function get()
    {
    	$cronjobs = $this->cronjobRepository->getCronjobs();

        return view('dashboard.cron-jobs')->with(['cronjobs' => $cronjobs]);
    }

    public function getCronJobByTag($tag)
    {
        $cronjobs = (object)$this->cronjobRepository->getCronjobsByTag($tag);

        return view('dashboard.cron-jobs')->with(['cronjobs' => $cronjobs, 'tag' => $tag]);
    }

    //Show add cronjobs form page
    public function add()
    {
        $pagetitle = 'Add CRON Job';
        $pageheader = 'Create new CRON Job Monitor';
        $function = 'add';
        return view('cronjobs.add')->with(['function'   => $function,
                                            'pagetitle' => $pagetitle,
                                            'pageheader' => $pageheader]
                                            );
    }   

    //Get all tags
    public function getTags()
    {
        return Response::json(array(
            'tags' => $this->tagRepository->getTags()
        ));
    }

    //Get all users
    public function getUsers()
    {
        return Response::json(array(
            'users' => $this->userRepository->getUsers()
        ));
    }
    
    public function getUserTeams()
    {
        return Response::json(array(
            'teams' => auth()->user()->teams
        ));
    }

    //Manage data. This is for both update and add
    public function dataManage(Request $request)
    {
        $cron = $this->cronjobRepository->updateOrCreateCronJob($request);

        return response()->json(['type' => 'success', 'message' =>'Cronjob Saved!', 'id' => $cron ],200);
    }

    // View the cronjob 
    public function view($id)
    {
        $cronjob = $this->cronjobRepository->getCronjobById($id);
        if($cronjob != null){
            $existingTags = [];

            
            foreach($cronjob->tags as $tag){
                $existingTags[] = $tag->name;
            }

        return view('cronjobs.view')->with(['cronjob' => $cronjob,
                                            'tags' => $existingTags,
                                            'hasEndpoint' => $cronjob->has_end_endpoint,
                                            'nooverlap' => $cronjob->nooverlap]
                                            );
        }
        
    }

    // Edit cronjob view
    public function edit($id)
    {
        $cronjob = $this->cronjobRepository->getCronjobById($id);
        if($cronjob != null){
            $existingTags = [];

        foreach($cronjob->tags as $tag){
            $existingTags[] = $tag->name;
        }

        $userid = auth()->user()->id;
        $pagetitle = 'Edit an existing CRON Job Monitor';
        $pageheader = 'Edit CRON Job';
        $function = 'edit';

        return view('cronjobs.add')->with(['cronjob' => $cronjob,
                                            'tags' => $existingTags,
                                            'nooverlap' => $cronjob->nooverlap,
                                            'userid' => $userid,
                                            'cronjobid' => $cronjob->id,
                                            'pagetitle' => $pagetitle,
                                            'pageheader' => $pageheader,
                                            'function' => $function]
                                            );
        }
    }

    public function translateTime(Request $request)
    {
        $cronjob = $this->cronjobRepository->translateCronTime($request->crontime);

        return $cronjob;
    }
}
