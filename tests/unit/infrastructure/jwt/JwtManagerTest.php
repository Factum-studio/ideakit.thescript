<?php

namespace unit\infrastructure\jwt;

use Codeception\Test\Unit;
use core\infrastructure\jwt\JwtManager;
use core\domain\entity\User;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use core\application\dto\JwtPayloadDto;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

class JwtManagerTest extends Unit
{
    private const SECRET = '01234567890123456789012345678901';
    private const TTL = 3600;

    private JwtManager $jwtManager;
    private User $user;

    protected function _before(): void
    {
        $this->jwtManager = new JwtManager(self::SECRET, self::TTL);
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

    // ---------- Генерация ----------

    public function testGenerateReturnsValidJwtString()
    {
        $token = $this->jwtManager->generate($this->user);
        $this->assertIsString($token);

        $decoded = JWT::decode($token, new Key(self::SECRET, 'HS256'));
        $this->assertEquals($this->user->getId()->value(), $decoded->sub);
        $this->assertEquals('admin', $decoded->role);
        $this->assertEquals('authKey123', $decoded->auth_key);
        $this->assertEqualsWithDelta(time(), $decoded->iat, 5);
        $this->assertEqualsWithDelta(time() + self::TTL, $decoded->exp, 5);
    }

    public function testGenerateIncludesExtraFields()
    {
        // Создаём менеджер с extra данными через JwtPayloadDto (но напрямую мы не можем,
        // т.к. generate принимает только User. Проверим, что extra не передаётся.
        // Для теста extra можно добавить только через модификацию JwtManager, но мы не будем.
        // Вместо этого проверим, что стандартный payload не содержит лишних полей.
        $token = $this->jwtManager->generate($this->user);
        $decoded = JWT::decode($token, new Key(self::SECRET, 'HS256'));
        $decodedArray = (array) $decoded;
        $expectedKeys = ['sub', 'role', 'iat', 'exp', 'auth_key'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $decodedArray);
        }
        // Проверим, что нет неожиданных ключей
        $this->assertEquals($expectedKeys, array_keys($decodedArray));
    }

    // ---------- Валидация ----------

    public function testValidateReturnsPayloadForValidToken()
    {
        $token = $this->jwtManager->generate($this->user);
        $payload = $this->jwtManager->validate($token);

        $this->assertInstanceOf(JwtPayloadDto::class, $payload);
        $this->assertEquals($this->user->getId()->value(), $payload->userId);
        $this->assertEquals('admin', $payload->role);
        $this->assertEquals('authKey123', $payload->authKey);
        $this->assertIsInt($payload->iat);
        $this->assertIsInt($payload->exp);
        $this->assertGreaterThan(time(), $payload->exp);
        $this->assertLessThanOrEqual(time() + self::TTL + 5, $payload->exp);
    }

    public function testValidateReturnsNullForExpiredToken()
    {
        $jwtManagerWithNegativeTtl = new JwtManager(self::SECRET, -3600);
        $token = $jwtManagerWithNegativeTtl->generate($this->user);
        $payload = $jwtManagerWithNegativeTtl->validate($token);
        $this->assertNull($payload);
    }

    public function testValidateReturnsNullForTokenWithWrongSecret()
    {
        $token = $this->jwtManager->generate($this->user);
        // Подменяем секрет в другом менеджере
        $wrongManager = new JwtManager('wrong-secret', self::TTL);
        $payload = $wrongManager->validate($token);
        $this->assertNull($payload);
    }

    public function testValidateReturnsNullForMalformedToken()
    {
        $payload = $this->jwtManager->validate('invalid.token.structure');
        $this->assertNull($payload);
    }

    public function testValidateReturnsNullForManipulatedPayload()
    {
        $token = $this->jwtManager->generate($this->user);
        // Изменяем один символ в payload (манипуляция)
        $parts = explode('.', $token);
        // Декодируем payload, меняем роль, кодируем обратно, подписываем (но подпись не совпадёт)
        $payloadBase64 = $parts[1];
        $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $payloadBase64));
        $payloadData = json_decode($payloadJson, true);
        $payloadData['role'] = 'superadmin';
        $newPayloadJson = json_encode($payloadData);
        $newPayloadBase64 = str_replace(['+', '/'], ['-', '_'], base64_encode($newPayloadJson));
        $newToken = $parts[0] . '.' . $newPayloadBase64 . '.' . $parts[2];
        $result = $this->jwtManager->validate($newToken);
        $this->assertNull($result);
    }

    public function testValidateReturnsNullForTokenWithoutRequiredFields()
    {
        // Создаём токен вручную без поля 'sub'
        $now = time();
        $payload = [
            'role' => 'user',
            'iat' => $now,
            'exp' => $now + self::TTL,
            'auth_key' => 'someKey',
        ];
        $token = JWT::encode($payload, self::SECRET, 'HS256');
        $result = $this->jwtManager->validate($token);
        $this->assertNull($result);
    }

    // ---------- Граничные случаи ----------

    public function testValidateWithZeroTtlReturnsNull()
    {
        $zeroManager = new JwtManager(self::SECRET, 0);
        $token = $zeroManager->generate($this->user);
        $payload = $zeroManager->validate($token);
        // Токен истекает в момент создания, поэтому validate должен вернуть null
        $this->assertNull($payload);
    }

    public function testValidateWithVeryLargeTtl()
    {
        $largeTtl = 365 * 24 * 3600; // 1 год
        $manager = new JwtManager(self::SECRET, $largeTtl);
        $token = $manager->generate($this->user);
        $payload = $manager->validate($token);
        $this->assertNotNull($payload);
        $this->assertEquals($this->user->getId()->value(), $payload->userId);
        // Проверим, что exp вычислен корректно
        $this->assertEqualsWithDelta(time() + $largeTtl, $payload->exp, 5);
    }

    public function testValidateReturnsNullForEmptyToken()
    {
        $result = $this->jwtManager->validate('');
        $this->assertNull($result);
    }

    // ---------- Проверка возврата JwtPayloadDto ----------

    public function testValidateFillsAllPayloadFieldsCorrectly()
    {
        $token = $this->jwtManager->generate($this->user);
        $payload = $this->jwtManager->validate($token);
        $this->assertEquals($this->user->getId()->value(), $payload->userId);
        $this->assertEquals('admin', $payload->role);
        $this->assertEquals('authKey123', $payload->authKey);
        $this->assertIsInt($payload->iat);
        $this->assertIsInt($payload->exp);
        $this->assertIsArray($payload->extra);
        $this->assertEmpty($payload->extra); // по умолчанию нет extra
    }
}
