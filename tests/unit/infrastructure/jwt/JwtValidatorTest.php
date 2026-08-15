<?php

namespace unit\infrastructure\jwt;

use Codeception\Test\Unit;
use core\infrastructure\jwt\JwtValidator;
use core\infrastructure\jwt\JwtManager;
use core\domain\entity\User;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use core\application\dto\JwtPayloadDto;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\MockObject\Exception;

class JwtValidatorTest extends Unit
{
    private const SECRET = 'test-secret';
    private const TTL = 3600;

    private JwtManager $jwtManager;
    private JwtValidator $validator;
    private User $user;

    protected function _before(): void
    {
        $this->jwtManager = new JwtManager(self::SECRET, self::TTL);
        $this->validator = new JwtValidator($this->jwtManager);

        $this->user = new User(
            UserId::generate(),
            'Doe',
            'John',
            null,
            null,
            null,
            new Role('admin'),
            null,
            new UserStatus(1),
            'authKey123',
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            null
        );
    }

    // ---------- validate() ----------

    public function testValidateReturnsTrueForValidToken()
    {
        $token = $this->jwtManager->generate($this->user);
        $this->assertTrue($this->validator->validate($token));
    }

    public function testValidateReturnsFalseForInvalidToken()
    {
        $this->assertFalse($this->validator->validate('invalid.token.structure'));
    }

    public function testValidateReturnsFalseForExpiredToken()
    {
        $managerWithNegativeTtl = new JwtManager(self::SECRET, -3600);
        $token = $managerWithNegativeTtl->generate($this->user);
        $validator = new JwtValidator($managerWithNegativeTtl);
        $this->assertFalse($validator->validate($token));
    }

    public function testValidateReturnsFalseForTokenWithWrongSecret()
    {
        $token = $this->jwtManager->generate($this->user);
        $wrongManager = new JwtManager('wrong-secret', self::TTL);
        $validator = new JwtValidator($wrongManager);
        $this->assertFalse($validator->validate($token));
    }

    public function testValidateReturnsFalseForManipulatedToken()
    {
        $token = $this->jwtManager->generate($this->user);
        $parts = explode('.', $token);
        $payloadBase64 = $parts[1];
        $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $payloadBase64));
        $payloadData = json_decode($payloadJson, true);
        $payloadData['role'] = 'superadmin';
        $newPayloadJson = json_encode($payloadData);
        $newPayloadBase64 = str_replace(['+', '/'], ['-', '_'], base64_encode($newPayloadJson));
        $newToken = $parts[0] . '.' . $newPayloadBase64 . '.' . $parts[2];
        $this->assertFalse($this->validator->validate($newToken));
    }

    public function testValidateReturnsFalseForEmptyToken()
    {
        $this->assertFalse($this->validator->validate(''));
    }

    // ---------- getPayload() ----------

    public function testGetPayloadReturnsDtoForValidToken()
    {
        $token = $this->jwtManager->generate($this->user);
        $payload = $this->validator->getPayload($token);

        $this->assertInstanceOf(JwtPayloadDto::class, $payload);
        $this->assertEquals($this->user->getId()->value(), $payload->userId);
        $this->assertEquals('admin', $payload->role);
        $this->assertEquals('authKey123', $payload->authKey);
        $this->assertIsInt($payload->iat);
        $this->assertIsInt($payload->exp);
    }

    public function testGetPayloadReturnsNullForInvalidToken()
    {
        $this->assertNull($this->validator->getPayload('invalid.token.structure'));
    }

    public function testGetPayloadReturnsNullForExpiredToken()
    {
        $managerWithNegativeTtl = new JwtManager(self::SECRET, -3600);
        $token = $managerWithNegativeTtl->generate($this->user);
        $validator = new JwtValidator($managerWithNegativeTtl);
        $this->assertNull($validator->getPayload($token));
    }

    public function testGetPayloadReturnsNullForTokenWithWrongSecret()
    {
        $token = $this->jwtManager->generate($this->user);
        $wrongManager = new JwtManager('wrong-secret', self::TTL);
        $validator = new JwtValidator($wrongManager);
        $this->assertNull($validator->getPayload($token));
    }

    public function testGetPayloadReturnsNullForManipulatedToken()
    {
        $token = $this->jwtManager->generate($this->user);
        $parts = explode('.', $token);
        $payloadBase64 = $parts[1];
        $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $payloadBase64));
        $payloadData = json_decode($payloadJson, true);
        $payloadData['user_id'] = 'hacked-id';
        $newPayloadJson = json_encode($payloadData);
        $newPayloadBase64 = str_replace(['+', '/'], ['-', '_'], base64_encode($newPayloadJson));
        $newToken = $parts[0] . '.' . $newPayloadBase64 . '.' . $parts[2];
        $this->assertNull($this->validator->getPayload($newToken));
    }

    // ---------- Проверка делегирования (с использованием мока) ----------

    /**
     * @throws Exception
     */
    public function testValidateCallsJwtManagerValidateWithSameToken()
    {
        $token = 'some-token';
        $managerMock = $this->createMock(JwtManager::class);
        $managerMock->expects($this->once())
            ->method('validate')
            ->with($token)
            ->willReturn(new JwtPayloadDto('id', 'user', time(), time() + 3600));

        $validator = new JwtValidator($managerMock);
        $result = $validator->validate($token);
        $this->assertTrue($result);
    }

    /**
     * @throws Exception
     */
    public function testGetPayloadCallsJwtManagerValidateWithSameToken()
    {
        $token = 'some-token';
        $expectedPayload = new JwtPayloadDto('id', 'user', time(), time() + 3600);
        $managerMock = $this->createMock(JwtManager::class);
        $managerMock->expects($this->once())
            ->method('validate')
            ->with($token)
            ->willReturn($expectedPayload);

        $validator = new JwtValidator($managerMock);
        $payload = $validator->getPayload($token);
        $this->assertSame($expectedPayload, $payload);
    }

    /**
     * @throws Exception
     */
    public function testValidateReturnsFalseWhenJwtManagerReturnsNull()
    {
        $managerMock = $this->createMock(JwtManager::class);
        $managerMock->method('validate')->willReturn(null);

        $validator = new JwtValidator($managerMock);
        $this->assertFalse($validator->validate('any-token'));
    }

    /**
     * @throws Exception
     */
    public function testGetPayloadReturnsNullWhenJwtManagerReturnsNull()
    {
        $managerMock = $this->createMock(JwtManager::class);
        $managerMock->method('validate')->willReturn(null);

        $validator = new JwtValidator($managerMock);
        $this->assertNull($validator->getPayload('any-token'));
    }
}
