<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Api;

use Illuminate\Http\Request;
use KodZero\POSMall\Classes\Api\ApiTokenGuard;
use KodZero\POSMall\Classes\Api\JsonResponseMarker;
use KodZero\POSMall\Models\ApiSettings;
use KodZero\POSMall\Models\ApiToken;
use Schema;

class ApiTokenGuardTest extends \TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable(ApiToken::TABLE)) {
            $this->markTestSkipped('POSMall API integration tables are not migrated in this PHPUnit connection.');
        }
    }

    public function test_api_rejects_missing_token(): void
    {
        ApiSettings::set('api_enabled', true);

        $result = app(ApiTokenGuard::class)->authenticate(Request::create('/posmall/api/v1/status'), ['catalog:read']);

        $this->assertInstanceOf(JsonResponseMarker::class, $result);
        $this->assertSame('missing_token', $result->code);
    }

    public function test_api_accepts_scoped_token(): void
    {
        ApiSettings::set('api_enabled', true);
        $plain = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name = 'phpunit';
        $token->plain_token = $plain;
        $token->scopes = ['catalog:read'];
        $token->save();

        $request = Request::create('/posmall/api/v1/status');
        $request->headers->set('Authorization', 'Bearer ' . $plain);

        $result = app(ApiTokenGuard::class)->authenticate($request, ['catalog:read']);

        $this->assertInstanceOf(ApiToken::class, $result);
        $this->assertSame($token->id, $result->id);
    }

    public function test_api_rejects_missing_scope(): void
    {
        ApiSettings::set('api_enabled', true);
        $plain = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name = 'phpunit';
        $token->plain_token = $plain;
        $token->scopes = ['catalog:read'];
        $token->save();

        $request = Request::create('/posmall/api/v1/cart/items');
        $request->headers->set('Authorization', 'Bearer ' . $plain);

        $result = app(ApiTokenGuard::class)->authenticate($request, ['cart:write']);

        $this->assertInstanceOf(JsonResponseMarker::class, $result);
        $this->assertSame('insufficient_scope', $result->code);
    }
}
