<?php

namespace App\Http\Controllers;

use App\Models\AboutUs;
use App\Models\ContactUs;
use App\Models\PrivacyPolicy;
use App\Models\TermsCondition;
use Illuminate\Http\Request;

class PageController extends Controller
{
    
    public function aboutUs()
    {
        $aboutUs = AboutUs::active()->ordered()->first();

        if (!$aboutUs) {
            abort(404, 'About Us page not found');
        }

        return view('pages.about-us', compact('aboutUs'));
    }

    
    public function termsConditions()
    {
        $terms = TermsCondition::active()->latest()->first();

        if (!$terms) {
            abort(404, 'Terms & Conditions not found');
        }

        return view('pages.terms-conditions', compact('terms'));
    }

    
    public function privacyPolicy()
    {
        $policy = PrivacyPolicy::active()->latest()->first();

        if (!$policy) {
            abort(404, 'Privacy Policy not found');
        }

        return view('pages.privacy-policy', compact('policy'));
    }

    
    public function contactUs()
    {
        $contactUs = ContactUs::active()->first();

        if (!$contactUs) {
            abort(404, 'Contact Us page not found');
        }

        return view('pages.contact-us', compact('contactUs'));
    }
}
