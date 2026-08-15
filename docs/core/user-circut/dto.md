# User Core DTO

## AuthRequestDto

```text
provider: string
providerClientId: string
userData: array
```

`userData` предназначен для данных пользователя от provider: surname, name, patronymic, email, phone и т. п.

## AuthResponseDto

```json
{
  "access_token": "<JWT>",
  "user": {}
}
```

## UserDto

Основные поля:

```text
id
surname
name
patronymic
email
phone
role
post
status
created_at
updated_at
last_login_at
```

`identities` добавляется только если handler получил identities для DTO.

## JwtPayloadDto

```text
sub
role
iat
exp
auth_key
extra
```

`extra` содержит неизвестные стандартному DTO claims после исключения `sub`, `role`, `iat`, `exp`, `auth_key`.

## CollectionDto

```json
{
  "items": [],
  "_meta": {
    "total": 100,
    "page": 1,
    "limit": 20,
    "pages": 5
  }
}
```

`_meta` появляется только если передан `total`.

## ErrorDto

```json
{
  "error": {
    "code": 400,
    "message": "...",
    "details": {}
  }
}
```

`details` добавляется только если массив не пуст.
