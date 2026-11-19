<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The bell: the latest notices, opening one marks it read. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(20)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->data['title'] ?? '', 'body' => $n->data['body'] ?? '', 'url' => $n->data['url'] ?? null,
                'kind' => $n->data['kind'] ?? 'info', 'read' => $n->read_at !== null, 'at' => $n->created_at->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
            ]),
        ]);
    }

    public function open(Request $request, string $id): RedirectResponse
    {
        $n = $request->user()->notifications()->findOrFail($id);
        $n->markAsRead();
        $url = $n->data['url'] ?? null;

        // only links into this system
        return $url && str_starts_with($url, url('/')) ? redirect()->to($url) : redirect()->route('dashboard');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
