<?php

namespace app\Support;

/**
 * 最小可用的 WebAuthn（Passkey）验证实现：
 * 校验 clientData / authenticatorData，提取 COSE 公钥并转换成 PEM，
 * 断言阶段用 openssl_verify 验证签名。仅支持 ES256 与 RS256。
 */
class WebAuthn
{
    public const CHALLENGE_TTL = 300;

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new \RuntimeException('凭证数据格式不正确');
        }
        $padded = strtr($value, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $bin = base64_decode($padded, true);
        if ($bin === false) {
            throw new \RuntimeException('凭证数据格式不正确');
        }
        return $bin;
    }

    public static function challenge(): string
    {
        return self::b64uEncode(random_bytes(32));
    }

    /**
     * 解析并校验创建（注册）响应，返回凭证信息
     *
     * @return array{credential_id:string, public_key:string, alg:int, sign_count:int}
     */
    public static function parseCreation(
        string $clientDataJson,
        string $attestationObject,
        string $expectedChallenge,
        string $expectedOrigin,
        string $expectedRpId
    ): array {
        self::verifyClientData($clientDataJson, 'webauthn.create', $expectedChallenge, $expectedOrigin);

        $offset = 0;
        $attestation = self::cborDecode($attestationObject, $offset);
        if (!is_array($attestation) || !isset($attestation['authData']) || !is_string($attestation['authData'])) {
            throw new \RuntimeException('无法解析通行密钥注册数据');
        }

        $authData = $attestation['authData'];
        self::verifyAuthenticatorData($authData, $expectedRpId, true);

        $flags = ord($authData[32]);
        if (($flags & 0x40) === 0) {
            throw new \RuntimeException('通行密钥未包含凭据公钥');
        }

        $credentialIdLength = unpack('n', substr($authData, 53, 2))[1];
        if ($credentialIdLength < 1 || strlen($authData) < 55 + $credentialIdLength) {
            throw new \RuntimeException('通行密钥凭据数据不完整');
        }
        $credentialId = substr($authData, 55, $credentialIdLength);
        $coseOffset = 55 + $credentialIdLength;
        $coseKey = self::cborDecode($authData, $coseOffset);
        if (!is_array($coseKey)) {
            throw new \RuntimeException('无法解析通行密钥公钥');
        }

        $publicKey = self::coseToPem($coseKey);
        if (!@openssl_pkey_get_public($publicKey)) {
            throw new \RuntimeException('通行密钥公钥无效');
        }

        return [
            'credential_id' => self::b64uEncode($credentialId),
            'public_key'    => $publicKey,
            'alg'           => (int) ($coseKey[3] ?? -7),
            'sign_count'    => unpack('N', substr($authData, 33, 4))[1],
        ];
    }

    /**
     * 校验断言（登录）响应，返回新的签名计数器
     */
    public static function parseAssertion(
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
        string $publicKeyPem,
        string $expectedChallenge,
        string $expectedOrigin,
        string $expectedRpId,
        int $storedSignCount
    ): int {
        self::verifyClientData($clientDataJson, 'webauthn.get', $expectedChallenge, $expectedOrigin);
        self::verifyAuthenticatorData($authenticatorData, $expectedRpId, false);

        $key = @openssl_pkey_get_public($publicKeyPem);
        if (!$key) {
            throw new \RuntimeException('通行密钥公钥无效');
        }
        $signed = $authenticatorData . hash('sha256', $clientDataJson, true);
        $result = @openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new \RuntimeException('通行密钥签名验证失败');
        }

        $signCount = unpack('N', substr($authenticatorData, 33, 4))[1];
        if ($storedSignCount > 0 && $signCount > 0 && $signCount <= $storedSignCount) {
            throw new \RuntimeException('通行密钥使用次数异常，请重新注册');
        }
        return $signCount;
    }

    private static function verifyClientData(
        string $clientDataJson,
        string $expectedType,
        string $expectedChallenge,
        string $expectedOrigin
    ): void {
        $data = json_decode($clientDataJson, true);
        if (!is_array($data)) {
            throw new \RuntimeException('无法解析通行密钥校验数据');
        }
        if ((string) ($data['type'] ?? '') !== $expectedType) {
            throw new \RuntimeException('通行密钥操作类型不匹配');
        }
        if (!isset($data['challenge']) || !is_string($data['challenge']) || !hash_equals($expectedChallenge, $data['challenge'])) {
            throw new \RuntimeException('通行密钥校验已过期，请重试');
        }
        $origin = rtrim((string) ($data['origin'] ?? ''), '/');
        if ($origin === '' || strcasecmp($origin, rtrim($expectedOrigin, '/')) !== 0) {
            throw new \RuntimeException('通行密钥来源校验未通过');
        }
    }

    private static function verifyAuthenticatorData(string $authData, string $expectedRpId, bool $requireAttestedData): void
    {
        if (strlen($authData) < 37) {
            throw new \RuntimeException('通行密钥认证数据不完整');
        }
        $rpIdHash = substr($authData, 0, 32);
        if (!hash_equals(hash('sha256', $expectedRpId, true), $rpIdHash)) {
            throw new \RuntimeException('通行密钥站点标识不匹配');
        }
        $flags = ord($authData[32]);
        if (($flags & 0x01) === 0) {
            throw new \RuntimeException('通行密钥未确认用户在场');
        }
        if ($requireAttestedData && strlen($authData) < 55) {
            throw new \RuntimeException('通行密钥认证数据缺少凭据信息');
        }
    }

    private static function coseToPem(array $key): string
    {
        $kty = (int) ($key[1] ?? 0);
        $alg = (int) ($key[3] ?? 0);

        if ($kty === 2) {
            if ($alg !== -7 || (int) ($key[-1] ?? 0) !== 1) {
                throw new \RuntimeException('暂不支持该通行密钥算法');
            }
            $x = $key[-2] ?? null;
            $y = $key[-3] ?? null;
            if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \RuntimeException('通行密钥椭圆曲线坐标无效');
            }
            $algorithm = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
            $spki = self::derSequence($algorithm . self::derBitString("\x04" . $x . $y));
        } elseif ($kty === 3) {
            if (!in_array($alg, [-257, -258], true)) {
                throw new \RuntimeException('暂不支持该通行密钥算法');
            }
            $n = $key[-1] ?? null;
            $e = $key[-2] ?? null;
            if (!is_string($n) || !is_string($e) || $n === '' || $e === '') {
                throw new \RuntimeException('通行密钥 RSA 公钥无效');
            }
            $rsaKey = self::derSequence(self::derInteger($n) . self::derInteger($e));
            $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
            $spki = self::derSequence($algorithm . self::derBitString($rsaKey));
        } else {
            throw new \RuntimeException('暂不支持该通行密钥类型');
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derBitString(string $content): string
    {
        return "\x03" . self::derLength(strlen($content) + 1) . "\x00" . $content;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * 最小 CBOR 解码器，覆盖 WebAuthn 用到的确定长度类型
     */
    private static function cborDecode(string $data, int &$offset)
    {
        $length = strlen($data);
        if ($offset >= $length) {
            throw new \RuntimeException('通行密钥数据不完整');
        }
        $initial = ord($data[$offset]);
        $offset += 1;
        $major = $initial >> 5;
        $additional = $initial & 0x1f;

        if ($additional < 24) {
            $value = $additional;
        } elseif ($additional === 24) {
            self::requireBytes($data, $offset, 1);
            $value = ord($data[$offset]);
            $offset += 1;
        } elseif ($additional === 25) {
            self::requireBytes($data, $offset, 2);
            $value = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($additional === 26) {
            self::requireBytes($data, $offset, 4);
            $value = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
        } elseif ($additional === 27) {
            self::requireBytes($data, $offset, 8);
            $parts = unpack('N2', substr($data, $offset, 8));
            $value = $parts[1] * 4294967296 + $parts[2];
            $offset += 8;
        } else {
            throw new \RuntimeException('通行密钥数据使用了不支持的编码');
        }

        switch ($major) {
            case 0:
                return $value;
            case 1:
                return -1 - $value;
            case 2:
            case 3:
                if ($value < 0 || $value > $length - $offset) {
                    throw new \RuntimeException('通行密钥数据长度异常');
                }
                $chunk = substr($data, $offset, $value);
                $offset += $value;
                return $chunk;
            case 4:
                $items = [];
                for ($i = 0; $i < $value; $i++) {
                    $items[] = self::cborDecode($data, $offset);
                }
                return $items;
            case 5:
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $key = self::cborDecode($data, $offset);
                    if (!is_int($key) && !is_string($key)) {
                        throw new \RuntimeException('通行密钥数据键名无效');
                    }
                    $map[$key] = self::cborDecode($data, $offset);
                }
                return $map;
            case 6:
                return self::cborDecode($data, $offset);
            case 7:
                if ($additional === 20) {
                    return false;
                }
                if ($additional === 21) {
                    return true;
                }
                if ($additional === 22 || $additional === 23) {
                    return null;
                }
                if ($additional === 26) {
                    return unpack('G', pack('N', $value))[1];
                }
                if ($additional === 27) {
                    return unpack('E', pack('N2', intdiv($value, 4294967296), $value % 4294967296))[1];
                }
                return null;
            default:
                throw new \RuntimeException('通行密钥数据格式无效');
        }
    }

    private static function requireBytes(string $data, int $offset, int $count): void
    {
        if (strlen($data) - $offset < $count) {
            throw new \RuntimeException('通行密钥数据不完整');
        }
    }
}
