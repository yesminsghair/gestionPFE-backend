<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiChatController extends Controller
{   
   public function chat(Request $request)
{
    $request->validate([
        'message' => 'required|string|max:2000'
    ]);

    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
        'Content-Type' => 'application/json'
    ])->post('https://api.groq.com/openai/v1/chat/completions', [
        'model' => 'llama-3.1-8b-instant',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Tu es un assistant académique pour étudiants PFE.'
            ],
            [
                'role' => 'user',
                'content' => $request->message
            ]
        ]
    ]);

    return response()->json($response->json());
}
}