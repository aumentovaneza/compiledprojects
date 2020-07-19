<?php 

namespace App\Repositories;

use App\Models\User;
use App\Models\Team;
use App\Models\Cronjobs;
use Spatie\Tags\Tag;
use App\Repositories\Interfaces\CronjobRepositoryInterface;
use Lorisleiva\CronTranslator\CronTranslator;

/**
 * This repository handles the data actions for cronjob 
 * 
 */
class CronjobRepository implements CronjobRepositoryInterface
{
    // Returns cronjob data
    public function getCronjobs()
    {
        $cronjobs = Cronjobs::orderBy('updated_at', 'desc')->get();

        return $cronjobs;
    }

    // Returns cronjob data by tag
    public function getCronjobsByTag($tag)
    {
        $cronjobs = Cronjobs::withAnyTags([$tag])->get();

        return $cronjobs;
    }

    // Returns cronjob by ID
    public function getCronjobById($id)
    {
    	$cronjob = Cronjobs::find($id);

    	return $cronjob;
    }

    // Updates or creates cronjob
    public function updateOrCreateCronJob($request)
    {
        $data = $this->formatData($request);
        try 
        {
            $cron = Cronjobs::find($request->data['id']);
            $id = null;

            if($cron != null)
            {
                $cron::where('id', $request->data['id'])->update($data);
                $id = $cron->id;
                $this->attachTags($request->tags, $cron);
                $this->saveOwnerId($request->data['ownership_type'], $cron);
            }
            else
            {
                $cron = Cronjobs::create($data);
                $id = $cron->id;
                $this->attachTags($request->tags, $cron);
                $this->saveOwnerId($request->data['ownership_type'], $cron);
            }

            
            
            return $id;
        } catch (\Exception $e) {
        // Handle Exception
        }
    }

    // Translates crontime to cronjob format
    public function translateCronTime($data)
    {
        try{
            return CronTranslator::translate($data);
        }
        catch(Exception $e)
        {
            return $e;
        }
    }

    // Formats data to be passed on update/create
    private function formatData($data)
    {
        return [
            'name' => $data->data['name'],
            'description' => $data->data['description'],
            'user_id'   => auth()->user()->id,
            'job_runfor' => $data->data['job_runfor'],
            'job_start' => $data->data['job_start'],
            'timezone' =>   isset($data->data['timezone']) ? $data->data['timezone'] : null,
            'has_end_endpoint' => $data->hasEndPoint,
            'nooverlap' => $data->noOverlap,
        ];
    }

    // Attaches cronjob to a tag
    private function attachTags($tagData, $cron)
    {
        $sep = ', ';
        $tags ='';

        if($tagData != null)
        {
            foreach($tagData as $tag => $value)
            {
                $tags .= $value['value'];
                $tags .= $sep; // add separator between sub-arrays
            }

            $tags = rtrim($tags, $sep); // remove last separator
            $tags = explode(', ', $tags);
            $cron->attachTags($tags);
        }
    }

    private function saveOwnerId($type, $cron)
    {
        $user = auth()->user();
        
        if($type == 'Single')
        {
            $user->cronjobs()->attach($cron);
        }
        else
        {
            auth()->user()->hasTeams()->teams();
        }
    }
}   