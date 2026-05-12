<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NewsletterController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191'],
            'lang' => ['sometimes', 'string', 'max:5'],
            'source' => ['sometimes', 'string', Rule::in(NewsletterSubscription::SOURCES)],
        ]);

        $email = Str::lower(trim($data['email']));
        $lang = Str::lower($data['lang'] ?? config('app.locale', 'en'));
        $source = $data['source'] ?? NewsletterSubscription::SOURCE_BOTTOM_FORM;

        $record = NewsletterSubscription::query()
            ->where('email', $email)
            ->where('lang', $lang)
            ->first();

        if ($record !== null) {
            if ($record->unsubscribed_at !== null) {
                $record->update([
                    'unsubscribed_at' => null,
                    'source' => $source,
                    'subscribed_at' => Carbon::now(),
                    'ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Already subscribed',
                'data' => ['id' => $record->id],
            ]);
        }

        $subscription = NewsletterSubscription::query()->create([
            'email' => $email,
            'lang' => $lang,
            'source' => $source,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'subscribed_at' => Carbon::now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subscribed',
            'data' => ['id' => $subscription->id],
        ], 201);
    }
}
