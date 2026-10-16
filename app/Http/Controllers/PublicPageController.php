<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Pages anyone can read without signing in: landing, terms, privacy. */
class PublicPageController extends Controller
{
    /** Shown as "Last updated" on the terms and privacy pages; bump when either changes. */
    public const UPDATED = 'October 16, 2026';

    public function welcome(): View
    {
        return view('public.welcome');
    }

    public function terms(): View
    {
        return view('public.terms');
    }

    public function privacy(): View
    {
        return view('public.privacy');
    }
}
