<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AboutUs;
use App\Models\ContactUs;
use App\Models\PrivacyPolicy;
use App\Models\TermsCondition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{

    public function getAboutUs(): JsonResponse
    {
        try {
            $aboutUs = AboutUs::active()
                ->ordered()
                ->first();

            if (!$aboutUs) {
                return response()->json([
                    'success' => false,
                    'message' => 'About Us content not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatAboutUs($aboutUs)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve About Us content',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getTermsConditions(): JsonResponse
    {
        try {
            $terms = TermsCondition::active()
                ->latest()
                ->first();

            if (!$terms) {
                return response()->json([
                    'success' => false,
                    'message' => 'Terms & Conditions not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatTermsConditions($terms)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Terms & Conditions',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getPrivacyPolicy(): JsonResponse
    {
        try {
            $policy = PrivacyPolicy::active()
                ->latest()
                ->first();

            if (!$policy) {
                return response()->json([
                    'success' => false,
                    'message' => 'Privacy Policy not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatPrivacyPolicy($policy)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Privacy Policy',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getContactUs(): JsonResponse
    {
        try {
            $contactUs = ContactUs::active()->first();

            if (!$contactUs) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact Us information not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatContactUs($contactUs)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Contact Us information',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    private function formatAboutUs(AboutUs $aboutUs): array
    {
        return [
            'id' => $aboutUs->id,
            'title' => $aboutUs->title,
            'content' => $aboutUs->content,
            'intro_text' => $aboutUs->intro_text,
            'sections' => $aboutUs->sections ?? [],
            'page_url' => url('/page/about-us'),
            'created_at' => $aboutUs->created_at,
            'updated_at' => $aboutUs->updated_at,
        ];
    }


    private function formatTermsConditions(TermsCondition $terms): array
    {
        return [
            'id' => $terms->id,
            'title' => $terms->title,
            'intro_text' => $terms->intro_text,
            'sections' => $this->formatSections($terms->sections ?? []),
            'conclusion_text' => $terms->conclusion_text,
            'version' => $terms->version,
            'effective_date' => $terms->effective_date?->toDateString(),
            'last_updated_at' => $terms->last_updated_at?->toDateString(),
            'page_url' => url('/page/terms-conditions'),
            'created_at' => $terms->created_at,
            'updated_at' => $terms->updated_at,
        ];
    }


    private function formatPrivacyPolicy(PrivacyPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'title' => $policy->title,
            'intro_text' => $policy->intro_text,
            'sections' => $this->formatPolicySections($policy->sections ?? []),
            'data_sharing_text' => $policy->data_sharing_text,
            'user_rights_text' => $policy->user_rights_text,
            'conclusion_text' => $policy->conclusion_text,
            'version' => $policy->version,
            'effective_date' => $policy->effective_date?->toDateString(),
            'last_updated_at' => $policy->last_updated_at?->toDateString(),
            'page_url' => url('/page/privacy-policy'),
            'created_at' => $policy->created_at,
            'updated_at' => $policy->updated_at,
        ];
    }


    private function formatContactUs(ContactUs $contactUs): array
    {
        $contacts = [];

        if ($contactUs->email) {
            $contacts[] = [
                'type' => 'email',
                'label' => 'Email',
                'value' => $contactUs->email,
                'icon' => 'envelope',
            ];
        }

        if ($contactUs->phone) {
            $contacts[] = [
                'type' => 'phone',
                'label' => 'Phone',
                'value' => $contactUs->phone,
                'icon' => 'phone',
            ];
        }

        if ($contactUs->office_address) {
            $contacts[] = [
                'type' => 'address',
                'label' => 'Office Address',
                'value' => $contactUs->office_address,
                'icon' => 'location',
            ];
        }

        if ($contactUs->additional_contacts) {
            $contacts = array_merge($contacts, $contactUs->additional_contacts);
        }

        return [
            'id' => $contactUs->id,
            'intro_text' => $contactUs->intro_text,
            'contacts' => $contacts,
            'email' => $contactUs->email,
            'phone' => $contactUs->phone,
            'office_address' => $contactUs->office_address,
            'support_message' => $contactUs->support_message,
            'working_hours' => $contactUs->working_hours,
            'page_url' => url('/page/contact-us'),
            'created_at' => $contactUs->created_at,
            'updated_at' => $contactUs->updated_at,
        ];
    }


    private function formatSections(array $sections): array
    {
        return array_map(function ($section) {
            return [
                'title' => $section['title'] ?? '',
                'content' => strip_tags($section['content'] ?? ''),
                'order' => $section['order'] ?? 0,
            ];
        }, $sections);
    }


    private function formatPolicySections(array $sections): array
    {
        return array_map(function ($section) {
            return [
                'type' => $section['type'] ?? '',
                'title' => $section['title'] ?? '',
                'content' => $section['content'] ?? '',
                'items' => $section['items'] ?? [],
            ];
        }, $sections);
    }
}
