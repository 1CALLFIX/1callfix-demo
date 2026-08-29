<?php

namespace Tests\Feature\Auth;

use App\Exceptions\FirebaseAuthException;
use App\Services\Auth\GoogleFirebaseTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The real RS256 verifier logic, exercised with a fixed throwaway RSA
 * keypair whose public half is seeded into the cert cache — no network, no
 * real Firebase project, but the actual signature / claim checks run.
 */
class FirebaseTokenVerificationTest extends TestCase
{
    private const PROJECT = 'demo-project';

    private const KID = 'test-kid';

    private const PRIVATE_KEY = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQC9yRB9nAFtZkuC
        UCq6BXQMf+9iRgSZZT42DkLC8F0gQbL1H8fJCSya20QaCiM0uXJ9UPZkiUvuYY7F
        r4q3rgcCJ34wFjW7F2DKd1aiTEFvtgSF9mO0qwHwAZSWAVHo1xHAl7LSQA4Rpr6m
        K699QsIiUVIXynukP/jGJRPzQIcgLUEHA+Oo2ICpRtONe8jQj/uFmYzMTGMH6yHG
        7yzLI/3deLe7spN8wf/8BNiXnm7KmtSU9eio8kYGRxncwYBPwQYRgj68q9AeDF3u
        WPX2llchtK/usrdNldHXQGUzr9AdwuDAnhhw1oNZGBiaa5a+NJoU4kHkpDoyu7J0
        FTBzaiaJAgMBAAECggEATnzaUJBVuMg+/yAnY3JA6bB5MFPTsBrCTTm9m4Ari10Q
        ZTak+bvNEigPNJOvtqzhL/ltROvRFr+K+6+a91bF+DqcAcgVpY/IDEOpdQqDX29P
        1JUu6/dzIC6PJDBcjyZu5Y73OeOazer/Wpqjg3K59dZa1JL9alK4kD2iUA4GNCQ7
        fqJeqdEFOgDL4CWhwK1i+sFWSkRflPfTVDSTOkIEn0WqbuDqHC9yE2CcA46l9ujc
        puKFRhyDbnygXq5mMjeC7iKbb6fOZ4S728AMzE3Ix6roMA6B0UZLnbOhiBj0K1xx
        HWnuGeSiJjgv2PUxI45rfWR9zJtoZJUYb3LF+snRXwKBgQD3hZAV1n/v3WQSf4RL
        GDD69ld6tJqaYzguX6kShdJ7pNnM/0vHrj0Ad8RrkzzWb+LKdHzTzwiGnJg0IWoS
        BQ8E6dEa3YKJbukH054NEe19JBq6wmYrnQ9RbR99cua96Dz26De+/UlwciJTU7C3
        q+qjB3UfciAWk17AGgY6PtPH6wKBgQDESTsLZfu6RAPofmwQzJGJozVPRy8LL9L2
        dqDHI8fXF/ra9Qqktjdf11g/c5fUZvPBuMWMhFJvGrhbshZb2BRmNp/kcu4l642t
        WIlAMUnOwrosFCocAFFpJ9WkpGl2qoGpzsZO/Nhp5jclO/PZXTjy7m1V+GpHKkjG
        sMrcq2bCWwKBgDViqkP7gpaCgo320Nq9efr23MFLaLj5w2lFGpszH8WpNYygV4DW
        1LNgIY4uMIXzlc+itjWcxrL53V4JAu6mBqpBn+cSdZAcysf0XXdmMXm3KsizGwQ3
        GNGwHoWZHalCCLwcM8HOsWM+Sqb8OvYybyYAesNwgvk7ickXE9bGLDlHAoGAOs+G
        NWAVEDYYxaw7TL0+TfLsohg97CgkGVxpx8Dcu4Gf08LfsYI3DSxEcJ59u1Itbrmh
        1vw+hrOG0VKGiHYxhn6PYa9d01bEWE/Sr70U1DJb/aD9DO67dbpNtMreHoPv3aTq
        nff8D56+nxVbdqEL0x3E/KE1lqUAsSd/YKaqX0kCgYB5m72k+Vuf3lFjMpbQ4FbA
        5v2CgtgnWPItDgI57bL8nvk/ivEZ3eN4J1jSEM8J3NWxN4em6Vv704agxtqvAiu+
        zzGDfokKxhEsDD00LOh12pQ97+8ozirU5t4bMmzwHSBJyMkvzya2eZrh2qyAvmdc
        ZAzVgxML9Rj0dbfDrMgFzA==
        -----END PRIVATE KEY-----
        PEM;

    private const PUBLIC_KEY = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvckQfZwBbWZLglAqugV0
        DH/vYkYEmWU+Ng5CwvBdIEGy9R/HyQksmttEGgojNLlyfVD2ZIlL7mGOxa+Kt64H
        Aid+MBY1uxdgyndWokxBb7YEhfZjtKsB8AGUlgFR6NcRwJey0kAOEaa+piuvfULC
        IlFSF8p7pD/4xiUT80CHIC1BBwPjqNiAqUbTjXvI0I/7hZmMzExjB+shxu8syyP9
        3Xi3u7KTfMH//ATYl55uyprUlPXoqPJGBkcZ3MGAT8EGEYI+vKvQHgxd7lj19pZX
        IbSv7rK3TZXR10BlM6/QHcLgwJ4YcNaDWRgYmmuWvjSaFOJB5KQ6MruydBUwc2om
        iQIDAQAB
        -----END PUBLIC KEY-----
        PEM;

    private GoogleFirebaseTokenVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('firebase:securetoken:x509', [self::KID => self::PUBLIC_KEY], now()->addHour());
        $this->verifier = new GoogleFirebaseTokenVerifier(self::PROJECT);
    }

    private function mint(array $overrides = []): string
    {
        $now = time();
        $claims = array_merge([
            'iss' => 'https://securetoken.google.com/'.self::PROJECT,
            'aud' => self::PROJECT,
            'auth_time' => $now,
            'iat' => $now,
            'exp' => $now + 3600,
            'sub' => 'firebase-uid-123',
            'user_id' => 'firebase-uid-123',
            'phone_number' => '+919876543210',
            'firebase' => ['sign_in_provider' => 'phone'],
        ], $overrides);

        return JWT::encode($claims, self::PRIVATE_KEY, 'RS256', self::KID);
    }

    public function test_a_valid_phone_token_yields_the_identity(): void
    {
        $identity = $this->verifier->verify($this->mint());

        $this->assertSame('firebase-uid-123', $identity->uid);
        $this->assertSame('+919876543210', $identity->phoneNumber);
        $this->assertTrue($identity->isPhoneProvider());
        $this->assertFalse($identity->isGoogleProvider());
    }

    public function test_a_google_token_is_recognised(): void
    {
        $identity = $this->verifier->verify($this->mint([
            'phone_number' => null,
            'email' => 'g@example.com',
            'email_verified' => true,
            'name' => 'Gee',
            'firebase' => ['sign_in_provider' => 'google.com'],
        ]));

        $this->assertTrue($identity->isGoogleProvider());
        $this->assertSame('g@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
    }

    public function test_a_token_for_a_different_project_is_rejected(): void
    {
        $this->expectException(FirebaseAuthException::class);
        $this->verifier->verify($this->mint(['aud' => 'someone-elses-project']));
    }

    public function test_a_token_with_the_wrong_issuer_is_rejected(): void
    {
        $this->expectException(FirebaseAuthException::class);
        $this->verifier->verify($this->mint(['iss' => 'https://evil.example.com/'.self::PROJECT]));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->expectException(FirebaseAuthException::class);
        $this->verifier->verify($this->mint(['exp' => time() - 300, 'iat' => time() - 3600]));
    }

    public function test_a_tampered_payload_breaks_signature_verification(): void
    {
        [$header, $payload, $signature] = explode('.', $this->mint());

        $claims = json_decode(JWT::urlsafeB64Decode($payload), true);
        $claims['sub'] = 'attacker-substituted-uid';
        $forged = $header.'.'.JWT::urlsafeB64Encode(json_encode($claims)).'.'.$signature;

        $this->expectException(FirebaseAuthException::class);
        $this->verifier->verify($forged);
    }

    public function test_an_empty_token_is_rejected(): void
    {
        $this->expectException(FirebaseAuthException::class);
        $this->verifier->verify('   ');
    }

    public function test_a_token_with_no_subject_is_rejected(): void
    {
        $this->expectException(FirebaseAuthException::class);
        $this->verifier->verify($this->mint(['sub' => '', 'user_id' => '']));
    }

    public function test_a_missing_project_id_configuration_is_reported(): void
    {
        $this->expectException(FirebaseAuthException::class);
        (new GoogleFirebaseTokenVerifier(null))->verify($this->mint());
    }
}
