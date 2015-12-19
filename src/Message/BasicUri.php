<?php
namespace Icicle\Http\Message;

use Icicle\Http\Exception\InvalidValueException;

/**
 * URI implementation based on phly/http URI implementation.
 *
 * @see https://github.com/phly/http
 */
class BasicUri implements Uri
{
    const UNRESERVED_CHARS = 'A-Za-z0-9_\-\.~';
    const GEN_DELIMITERS = ':\/\?#\[\]@';
    const SUB_DELIMITERS = '!\$&\'\(\)\*\+,;=';
    const ENCODED_CHAR = '%(?![A-Fa-f0-9]{2})';

    /**
     * Array of valid schemes to corresponding port numbers.
     *
     * @var int[]
     */
    private static $schemes = [
        'http'  => 80,
        'https' => 443,
    ];

    /**
     * @var string
     */
    private $scheme;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int|null
     */
    private $port;

    /**
     * @var string
     */
    private $user;

    /**
     * @var string|null
     */
    private $password;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string[]
     */
    private $query = [];

    /**
     * @var string
     */
    private $fragment;

    /**
     * @param string $uri
     *
     * @throws \Icicle\Http\Exception\InvalidValueException
     */
    public function __construct($uri = '')
    {
        $this->parseUri((string) $uri);
    }

    /**
     * {@inheritdoc}
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthority(): string
    {
        $authority = $this->getHost();
        if (!$authority) {
            return '';
        }

        $userInfo = $this->getUserInfo();
        if ($userInfo) {
            $authority = sprintf('%s@%s', $userInfo, $authority);
        }

        $port = $this->getPort();
        if ($port && $this->getPortForScheme() !== $port) {
            $authority = sprintf('%s:%s', $authority, $this->getPort());
        }

        return $authority;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserInfo(): string
    {
        if ('' !== $this->password) {
            return sprintf('%s:%s', $this->user, $this->password);
        }

        return $this->user;
    }

    /**
     * {@inheritdoc}
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * {@inheritdoc}
     */
    public function getPort(): int
    {
        if (0 === $this->port) {
            return $this->getPortForScheme();
        }

        return $this->port;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     */
    public function getQuery(): string
    {
        if (empty($this->query)) {
            return '';
        }

        $query = [];

        foreach ($this->query as $name => $value) {
            if ('' === $value) {
                $query[] = $name;
            } else {
                $query[] = sprintf('%s=%s', $name, $value);
            }
        }

        return implode('&', $query);
    }

    /**
     * {@inheritdoc}
     */
    public function getQueryValues(): array
    {
        return $this->query;
    }

    /**
     * {@inheritdoc}
     */
    public function hasQueryValue(string $name): bool
    {
        return isset($this->query[$this->encodeValue($name)]);
    }

    /**
     * {@inheritdoc}
     */
    public function getQueryValue(string $name): string
    {
        $name = $this->encodeValue($name);

        return isset($this->query[$name]) ? $this->query[$name] : '';
    }

    /**
     * {@inheritdoc}
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * {@inheritdoc}
     */
    public function withScheme(string $scheme = null): Uri
    {
        $new = clone $this;
        $new->scheme = $new->filterScheme($scheme);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withUserInfo(string $user = null, string $password = null): Uri
    {
        $new = clone $this;

        $new->user = $new->encodeValue($user);
        $new->password = $new->encodeValue($password);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withHost(string $host = null): Uri
    {
        $new = clone $this;
        $new->host = (string) $host;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withPort(int $port = null): Uri
    {
        $new = clone $this;
        $new->port = $new->filterPort($port);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withPath(string $path = null): Uri
    {
        $new = clone $this;
        $new->path = $new->parsePath($path);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withQuery(string $query = null): Uri
    {
        $new = clone $this;
        $new->query = $new->parseQuery($query);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withFragment(string $fragment = null): Uri
    {
        $new = clone $this;
        $new->fragment = $new->parseFragment($fragment);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withQueryValue(string $name, $value): Uri
    {
        $new = clone $this;

        $name = $new->encodeValue($name);
        $value = $new->encodeValue($value);

        $new->query[$name] = $value;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withoutQueryValue(string $name): Uri
    {
        $new = clone $this;

        $name = $this->encodeValue($name);

        unset($new->query[$name]);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        $uri = $this->getAuthority();

        if (!empty($uri)) {
            $scheme = $this->getScheme();
            if ($scheme) {
                $uri = sprintf('%s://%s', $scheme, $uri);
            }
        }

        $uri .= $this->getPath();

        $query = $this->getQuery();
        if ($query) {
            $uri = sprintf('%s?%s', $uri, $query);
        }

        $fragment = $this->getFragment();
        if ($fragment) {
            $uri = sprintf('%s#%s', $uri, $fragment);
        }

        return $uri;
    }

    /**
     * Returns the default port for the current scheme or null if no scheme is set.
     *
     * @return int
     */
    protected function getPortForScheme(): int
    {
        $scheme = $this->getScheme();

        if (!$scheme) {
            return 0;
        }

        return $this->allowedSchemes()[$scheme];
    }

    /**
     * @param string $uri
     *
     * @throws \Icicle\Http\Exception\InvalidValueException
     */
    private function parseUri($uri)
    {
        $components = parse_url($uri);

        if (!$components) {
            throw new InvalidValueException('Invalid URI.');
        }

        $this->scheme   = isset($components['scheme'])   ? $this->filterScheme($components['scheme']) : '';
        $this->host     = isset($components['host'])     ? $components['host'] : '';
        $this->port     = isset($components['port'])     ? $this->filterPort($components['port']) : 0;
        $this->user     = isset($components['user'])     ? $this->encodeValue($components['user']) : '';
        $this->password = isset($components['pass'])     ? $this->encodeValue($components['pass']) : '';
        $this->path     = isset($components['path'])     ? $this->parsePath($components['path']) : '';
        $this->query    = isset($components['query'])    ? $this->parseQuery($components['query']) : [];
        $this->fragment = isset($components['fragment']) ? $this->parseFragment($components['fragment']) : '';
    }

    /**
     * @return int[] Array indexed by valid scheme names to their corresponding ports.
     */
    protected function allowedSchemes(): array
    {
        return self::$schemes;
    }

    /**
     * @param string|null $scheme
     *
     * @return string
     *
     * @throws \Icicle\Http\Exception\InvalidValueException
     */
    protected function filterScheme(string $scheme = null): string
    {
        if (null === $scheme) {
            return '';
        }

        $scheme = strtolower($scheme);
        $scheme = rtrim($scheme, ':/');

        if ('' !== $scheme && !array_key_exists($scheme, $this->allowedSchemes())) {
            throw new InvalidValueException(sprintf(
                    'Invalid scheme: %s. Must be null, an empty string, or in set (%s).',
                    $scheme,
                    implode(', ', array_keys($this->allowedSchemes()))
                ));
        }

        return $scheme;
    }

    /**
     * @param int $port
     *
     * @return int
     *
     * @throws \Icicle\Http\Exception\InvalidValueException
     */
    protected function filterPort(int $port = null): int
    {
        $port = (int) $port; // Cast null to 0.

        if (0 > $port || 0xffff < $port) {
            throw new InvalidValueException(
                sprintf('Invalid port: %d. Must be 0 or an integer between 1 and 65535.', $port)
            );
        }

        return $port;
    }

    /**
     * @param string|null $path
     *
     * @return string
     */
    protected function parsePath(string $path = null): string
    {
        if ('' === $path || null === $path) {
            return '';
        }

        $path = ltrim($path, '/');

        $path = '/' . $path;

        return $this->encodePath($path);
    }

    /**
     * @param string|null $query
     *
     * @return string[]
     */
    protected function parseQuery(string $query = null): array
    {
        $query = ltrim($query, '?');

        $fields = [];

        foreach (explode('&', $query) as $data) {
            list($name, $value) = $this->parseQueryPair($data);
            if ('' !== $name) {
                $fields[$name] = $value;
            }
        }

        ksort($fields);

        return $fields;
    }

    /**
     * @param string $data
     *
     * @return array
     */
    protected function parseQueryPair(string $data): array
    {
        $data = explode('=', $data, 2);
        if (1 === count($data)) {
            return [$this->encodeValue($data[0]), ''];
        }
        return [$this->encodeValue($data[0]), $this->encodeValue($data[1])];
    }

    /**
     * @param string $fragment
     *
     * @return string
     */
    protected function parseFragment(string $fragment = null): string
    {
        $fragment = ltrim($fragment, '#');

        return $this->encodeValue($fragment);
    }

    /**
     * Escapes all reserved chars and sub delimiters.
     *
     * @param string $string
     *
     * @return string
     */
    protected function encodePath(string $string = null): string
    {
        return preg_replace_callback(
            '/(?:[^' . self::UNRESERVED_CHARS . '\/%]+|' . self::ENCODED_CHAR . ')/',
            function (array $matches) {
                return rawurlencode($matches[0]);
            },
            $string
        );
    }

    /**
     * Escapes all reserved chars.
     *
     * @param string $string
     *
     * @return string
     */
    protected function encodeValue(string $string = null): string
    {
        return preg_replace_callback(
            '/(?:[^' . self::UNRESERVED_CHARS . self::SUB_DELIMITERS . '\/%]+|' . self::ENCODED_CHAR . ')/',
            function (array $matches) {
                return rawurlencode($matches[0]);
            },
            $string
        );
    }
}
