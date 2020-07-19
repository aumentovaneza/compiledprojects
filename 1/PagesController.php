<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Models\AdminContentManage;

class PagesController extends Controller
{
    /**
     * Homepage
     *
     * @return \Illuminate\Http\Response
     */
    public function home()
    {
        return view('home.index');
    }

    /**
     * Privacy Policy page
     *
     * @return \Illuminate\Http\Response
     */
    public function privacyPolicy()
    {
        $content = AdminContentManage::where('content_type','privacypolicy')->first();
        return view('pages.privacy-policy')->with('content', $content->content);
    }

    /**
     * Terms of Service page
     *
     * @return \Illuminate\Http\Response
     */
    public function termsOfService()
    {
        $content = AdminContentManage::where('content_type','terms')->first();
        return view('pages.terms-of-service')->with('content', $content->content);
    }

    /**
     * Contact page
     *
     * @return \Illuminate\Http\Response
     */
    public function getContact()
    {
        return view('pages.contact');
    }

    public function getTranslatedGuidelinesPage()
    {
        return view('cronjobs.translated');
    }
}
