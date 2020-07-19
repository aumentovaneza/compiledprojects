<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Socialite;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Auth;

class ThirdPartyLoginController extends Controller
{
    private $parameters;

    public function redirect($service)
    {
        if($service == 'github'){
            $this->parameters = [
                'redirect_uri' => url('/thirdPartyLogin/callback/github')
            ];
        }
        if($this->parameters){
            return Socialite::driver($service)->with($this->parameters)->redirect();
        }else{
            return Socialite::driver($service)->redirect();
        }
    }

        public function callback($service)
    {
        try {
            $thisUser = Socialite::driver($service)->user();
            $service_id = $service . '_id';
            $existUser = null;
            if(Auth::user()){
                $existUser = Auth::user();
            }elseif (User::where($service_id,'=',$thisUser->id)->exists()){
                $existUser = User::where($service_id,$thisUser->id)->first();
            }else{
                $existUser = User::where('email',$thisUser->email)->first();
            }

            if($existUser) {
                if(User::where($service_id,'=',$thisUser->id)->exists() && Auth::user()){
                    session()->flash($service . '_error','The service was already linked to another account');
                    return redirect('/dashboard');
                }
                $existId = $existUser->$service_id;
                if(! $existId){
                    $existUser->$service_id = $thisUser->id;
                    $existUser->avatar = $thisUser->avatar;
                    $existUser->save();

                }
                if(Auth::user()){
                    return redirect('/dashboard');
                }else{
                    Auth::loginUsingId($existUser->id);
                }
            }
            else {
                $user = new User;
                $user->name = $thisUser->name;
                $user->email = $thisUser->email;
                $user->$service_id = $thisUser->id;
                $user->password = md5(rand(1,10000));
                $user->email_verified_at = Carbon::now()->toDateTimeString();
                $user->avatar = $thisUser->avatar;
                $user->save();
                Auth::loginUsingId($user->id);
            }
            return redirect()->to('/dashboard');
        }
        catch (Exception $e) {
            return 'error: '.$e;
        }
    }
}
