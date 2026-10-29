<?php

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    useFakeRegistry();
    Http::preventStrayRequests();
    Mail::fake();
    config(['greenpoints.email_verification' => [
        'code_ttl_minutes' => 15,
        'resend_cooldown_seconds' => 60,
        'max_attempts' => 5,
    ]]);
});

/** Register through the API (registry check included) and return [token, user]. */
function registerUnverified(object $test): array
{
    Http::fake([FAKE_REGISTRY.'/api/v1/students/*' => Http::response(registryStudent())]);

    $response = apiRequest($test, 'POST', '/auth/register', null, [
        'student_id' => '2024-00309',
        'email' => 'ana@student.test',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ])->assertCreated();

    return [$response->json('token'), User::findOrFail($response->json('user.id'))];
}

function lastCode(): string
{
    return Mail::sent(EmailVerificationCode::class)->last()->code;
}

function wrongCode(): string
{
    return lastCode() === '000000' ? '111111' : '000000';
}

it('logs a registered student in unverified, emails a code and blocks the app until it is entered', function () {
    Http::fake([FAKE_REGISTRY.'/api/v1/students/*' => Http::response(registryStudent())]);

    $response = apiRequest($this, 'POST', '/auth/register', null, [
        'student_id' => '2024-00309',
        'email' => 'ana@student.test',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ])->assertCreated()
        ->assertJsonPath('user.email_verified', false);
    $token = $response->json('token');

    Mail::assertSent(EmailVerificationCode::class, fn ($mail) => $mail->hasTo('ana@student.test')
        && preg_match('/^\d{6}$/', $mail->code));

    apiRequest($this, 'GET', '/me', $token)->assertOk()->assertJsonPath('data.email_verified', false);
    apiRequest($this, 'GET', '/dashboard', $token)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

    apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => wrongCode()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => lastCode()])
        ->assertOk()
        ->assertJsonPath('user.email_verified', true);

    apiRequest($this, 'GET', '/dashboard', $token)->assertOk();
    $this->assertDatabaseCount('email_verifications', 0);
});

it('does not email a code when the registry rejects the student', function () {
    Http::fake([FAKE_REGISTRY.'/api/v1/students/*' => Http::response(['error' => ['code' => 'STUDENT_NOT_FOUND']], 404)]);

    apiRequest($this, 'POST', '/auth/register', null, [
        'student_id' => '2024-00309',
        'email' => 'ana@student.test',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ])->assertUnprocessable();

    Mail::assertNothingSent();
});

it('enforces the resend cooldown on the server', function () {
    [$token] = registerUnverified($this);

    apiRequest($this, 'GET', '/auth/email/verification', $token)
        ->assertOk()
        ->assertJsonPath('data.verified', false)
        ->assertJsonPath('data.resend_available_in', 60);

    $this->travel(30)->seconds();
    apiRequest($this, 'POST', '/auth/email/verification/resend', $token)
        ->assertStatus(429)
        ->assertHeader('Retry-After', '30')
        ->assertJsonPath('error.code', 'RESEND_COOLDOWN')
        ->assertJsonPath('error.details.retry_after', 30);
    Mail::assertSentCount(1);

    $firstCode = lastCode();
    $this->travel(31)->seconds();
    apiRequest($this, 'POST', '/auth/email/verification/resend', $token)
        ->assertOk()
        ->assertJsonPath('data.resend_available_in', 60);
    Mail::assertSentCount(2);

    // The cooldown starts again after every send.
    apiRequest($this, 'POST', '/auth/email/verification/resend', $token)->assertStatus(429);
    Mail::assertSentCount(2);

    // Only the newest code works.
    if ($firstCode !== lastCode()) {
        apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => $firstCode])->assertUnprocessable();
    }
    apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => lastCode()])->assertOk();
});

it('lets an unverified user log in again, still stuck at verification, with the cooldown kept', function () {
    [$oldToken] = registerUnverified($this);
    apiRequest($this, 'POST', '/auth/logout', $oldToken)->assertOk();

    $this->travel(10)->seconds();
    $token = apiRequest($this, 'POST', '/auth/login', null, [
        'email' => 'ana@student.test',
        'password' => 'secret-pass-1',
    ])->assertOk()
        ->assertJsonPath('user.email_verified', false)
        ->json('token');

    apiRequest($this, 'GET', '/wallet', $token)->assertForbidden()->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    apiRequest($this, 'GET', '/auth/email/verification', $token)->assertJsonPath('data.resend_available_in', 50);
    apiRequest($this, 'POST', '/auth/email/verification/resend', $token)->assertStatus(429);
    Mail::assertSentCount(1); // logging in does not send a new code

    // The code from registration still works after logging in again.
    apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => lastCode()])->assertOk();
    apiRequest($this, 'GET', '/wallet', $token)->assertOk();
});

it('burns the code after too many wrong attempts', function () {
    [$token] = registerUnverified($this);

    foreach ([4, 3, 2, 1] as $left) {
        apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => wrongCode()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', "That code is incorrect. {$left} ".($left === 1 ? 'try' : 'tries').' left.');
    }
    apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => wrongCode()])->assertUnprocessable();

    // Even the right code is refused now.
    apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => lastCode()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'This code has expired or was entered wrong too many times. Please request a new code.');

    $this->travel(61)->seconds();
    apiRequest($this, 'POST', '/auth/email/verification/resend', $token)->assertOk();
    apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => lastCode()])->assertOk();
});

it('rejects an expired code', function () {
    [$token] = registerUnverified($this);

    $this->travel(16)->minutes();
    apiRequest($this, 'POST', '/auth/email/verify', $token, ['code' => lastCode()])->assertUnprocessable();
    apiRequest($this, 'GET', '/auth/email/verification', $token)->assertJsonPath('data.code_expires_at', fn ($v) => $v !== null);
});

it('still registers when the mail server fails, and allows an immediate resend', function () {
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

    [$token, $user] = registerUnverified($this);
    expect($user->email_verified_at)->toBeNull();

    apiRequest($this, 'GET', '/auth/email/verification', $token)
        ->assertJsonPath('data.resend_available_in', 0)
        ->assertJsonPath('data.code_expires_at', null);
});

it('answers 503 when a resend cannot be delivered, without starting the cooldown', function () {
    [$token] = registerUnverified($this);
    $this->travel(61)->seconds();

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));
    apiRequest($this, 'POST', '/auth/email/verification/resend', $token)
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'MAIL_UNAVAILABLE');

    apiRequest($this, 'GET', '/auth/email/verification', $token)->assertJsonPath('data.resend_available_in', 0);
});

it('leaves verified accounts alone', function () {
    $user = User::factory()->create();

    apiRequest($this, 'GET', '/dashboard', $user)->assertOk();
    apiRequest($this, 'POST', '/auth/email/verification/resend', $user)->assertOk();
    apiRequest($this, 'GET', '/auth/email/verification', $user)->assertJsonPath('data.verified', true);
    Mail::assertNothingSent();
});
