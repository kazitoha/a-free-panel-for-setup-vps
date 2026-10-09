<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidMailRecord;
use App\Exceptions\StalwartError;
use App\Services\MailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MailboxController extends Controller
{
    public function __construct(private readonly MailService $mail) {}

    public function store(Request $request, string $domain): RedirectResponse
    {
        if ($this->mail->status() !== 'ok') {
            return redirect()
                ->route('mail.index')
                ->with('error', 'Stalwart is not reachable.');
        }

        $data = $this->resolveDomain($domain);

        if ($data === null) {
            return redirect()
                ->route('mail.index')
                ->with('error', 'That mail domain no longer exists.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:256'],
            'description' => ['nullable', 'string', 'max:200'],
        ], [], ['name' => 'mailbox name', 'description' => 'description']);

        try {
            $mailbox = $this->mail->createMailbox($data['id'], $data['name'], $request->only([
                'name', 'password', 'description',
            ]));
        } catch (InvalidMailRecord $exception) {
            return back()->withErrors($exception->errors)->withInput();
        } catch (StalwartError $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('mail.show', $data['id'])
            ->with('success', "Mailbox {$mailbox['email']} created.");
    }

    public function update(Request $request, string $domain, string $mailbox): RedirectResponse
    {
        if ($this->mail->status() !== 'ok') {
            return redirect()
                ->route('mail.index')
                ->with('error', 'Stalwart is not reachable.');
        }

        $request->validate([
            'password' => ['required', 'string', 'max:256'],
        ]);

        try {
            $this->mail->resetMailboxPassword($mailbox, (string) $request->input('password'));
        } catch (InvalidMailRecord $exception) {
            return back()->withErrors($exception->errors);
        } catch (StalwartError $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Password reset.');
    }

    public function destroy(string $domain, string $mailbox): RedirectResponse
    {
        if ($this->mail->status() !== 'ok') {
            return redirect()
                ->route('mail.index')
                ->with('error', 'Stalwart is not reachable.');
        }

        try {
            $this->mail->deleteMailbox($mailbox);
        } catch (StalwartError $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('mail.show', $domain)
            ->with('success', 'Mailbox deleted.');
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function resolveDomain(string $id): ?array
    {
        try {
            return $this->mail->domain($id);
        } catch (StalwartError) {
            return null;
        }
    }
}
