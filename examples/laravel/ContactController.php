<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Aegis\Captcha\Adapters\Laravel\Rules\AegisHumanRule;

class ContactController extends Controller
{
    /**
     * Show the contact form.
     */
    public function create()
    {
        return view('contact');
    }

    /**
     * Store contact message protected by Aegis.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email',
            'message' => 'required|string|min:10',
            'aegis_payload' => ['required', new AegisHumanRule()],
        ]);

        // Process message safely...
        return back()->with('status', 'Thank you! Your message was sent successfully.');
    }
}
