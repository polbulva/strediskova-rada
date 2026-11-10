<?php

/**
 * Minimální klient skautISu (bez Composeru), založený na protokolu knihovny skaut/Skautis:
 * přihlášení přesměrováním na Login/?appid=…, návrat POSTem (skautIS_Token, skautIS_IDRole,
 * skautIS_IDUnit, skautIS_DateLogout) a SOAP volání s ID_Application a ID_Login.
 */
final class SkautisException extends RuntimeException
{
}

final class SkautisClient
{
    /** @var array<string, SoapClient> */
    private array $clients = [];

    public function __construct(private readonly string $appId, private readonly bool $testMode)
    {
        if ($appId === '') {
            throw new SkautisException('Není nastaveno ID aplikace skautIS (skautis_app_id).');
        }
        if (!extension_loaded('soap')) {
            throw new SkautisException('PHP rozšíření SOAP není na hostingu zapnuto.');
        }
    }

    public function baseUrl(): string
    {
        return $this->testMode ? 'https://test-is.skaut.cz/' : 'https://is.skaut.cz/';
    }

    public function loginUrl(string $returnUrl): string
    {
        return $this->baseUrl() . 'Login/?' . http_build_query(['appid' => $this->appId, 'ReturnUrl' => $returnUrl], '', '&');
    }

    public function logoutUrl(string $token): string
    {
        return $this->baseUrl() . 'Login/LogOut.aspx?' . http_build_query(['appid' => $this->appId, 'token' => $token], '', '&');
    }

    /** @return array{token: string, role_id: ?int, unit_id: ?int, logout_at: ?int} */
    public static function parseLoginData(array $post): array
    {
        $token = trim((string) ($post['skautIS_Token'] ?? ''));
        if (!preg_match('/^[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/', $token)) {
            throw new SkautisException('Skautis nevrátil platný přihlašovací token.');
        }

        $logout = null;
        $date = DateTimeImmutable::createFromFormat('j. n. Y H:i:s', trim((string) ($post['skautIS_DateLogout'] ?? '')));
        if ($date) {
            $logout = $date->getTimestamp();
        }

        $int = static fn (string $key): ?int => ctype_digit((string) ($post[$key] ?? '')) ? (int) $post[$key] : null;

        return ['token' => $token, 'role_id' => $int('skautIS_IDRole'), 'unit_id' => $int('skautIS_IDUnit'), 'logout_at' => $logout];
    }

    /**
     * @return array<int|string, mixed>|stdClass|string|int|bool|null
     */
    public function call(string $service, string $method, array $arguments, string $token): mixed
    {
        $method = ucfirst($method);
        try {
            $arguments += ['ID_Application' => $this->appId, 'ID_Login' => $token];
            $result = $this->client($service)->__soapCall($method, [[lcfirst($method) . 'Input' => $arguments]]);
        } catch (Throwable $exception) {
            throw new SkautisException('Volání skautISu selhalo: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->parse($method, $result);
    }

    private function client(string $service): SoapClient
    {
        if (!isset($this->clients[$service])) {
            $this->clients[$service] = new SoapClient(
                $this->baseUrl() . 'JunakWebservice/' . rawurlencode($service) . '.asmx?WSDL',
                [
                    'soap_version' => SOAP_1_2,
                    'encoding' => 'utf-8',
                    'exceptions' => true,
                    'cache_wsdl' => WSDL_CACHE_BOTH,
                    'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
                    'connection_timeout' => 10,
                    'user_agent' => 'strediskova-rada',
                    'stream_context' => stream_context_create([
                        'ssl' => ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT],
                    ]),
                ]
            );
        }
        return $this->clients[$service];
    }

    private function parse(string $method, mixed $response): mixed
    {
        if (!$response instanceof stdClass || (array) $response === []) {
            return null;
        }

        $key = $method . 'Result';
        $result = $response->$key ?? null;
        if (!$result instanceof stdClass) {
            return $result;
        }

        $output = $result->{$method . 'Output'} ?? null;
        if ($output === null) {
            return (array) $result === [] ? [] : $result;
        }

        return is_array($output) ? $output : [$output];
    }
}

function skautisClient(): SkautisClient
{
    static $client = null;
    $config = appSettings();
    return $client ??= new SkautisClient((string) $config['skautis_app_id'], (bool) $config['skautis_test']);
}

function skautisConfigured(): bool
{
    return trim((string) appSettings()['skautis_app_id']) !== '' && extension_loaded('soap');
}

/**
 * Ověří token ve skautISu a vrátí identitu přihlášeného včetně zvolené role.
 *
 * @return array{username: string, name: string, role_key: string, role_name: string, unit_id: ?int, unit_name: string, logout_at: ?int}
 */
function skautisIdentity(array $login): array
{
    $client = skautisClient();
    $token = $login['token'];

    $user = $client->call('UserManagement', 'UserDetail', [], $token);
    if (!$user instanceof stdClass) {
        throw new SkautisException('Skautis nevrátil údaje o uživateli.');
    }

    $roles = $client->call('UserManagement', 'UserRoleAll', ['ID_User' => $user->ID ?? null, 'ActiveOnly' => true], $token);
    $roles = is_array($roles) ? $roles : [];

    $selected = null;
    foreach ($roles as $role) {
        if ($login['role_id'] !== null && (int) ($role->ID ?? 0) === $login['role_id']) {
            $selected = $role;
            break;
        }
    }
    $selected ??= $roles[0] ?? null;

    $username = (string) ($user->UserName ?? '');
    $person = trim((string) ($user->Person ?? $user->PersonDisplayName ?? ''));

    return [
        'username' => $username,
        'name' => $person !== '' ? $person : $username,
        'role_key' => $selected ? (string) ($selected->Role ?? $selected->ID_Role ?? '') : '',
        'role_name' => $selected ? (string) ($selected->DisplayName ?? $selected->Role ?? '') : '',
        'unit_id' => $login['unit_id'] ?? ($selected->ID_Unit ?? null),
        'unit_name' => $selected ? (string) ($selected->Unit ?? '') : '',
        'logout_at' => $login['logout_at'],
    ];
}
