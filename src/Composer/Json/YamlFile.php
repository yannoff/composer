<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Json;

use JsonSchema\Validator;
use RomaricDrigon\MetaYaml\Loader\JsonLoader;
use RomaricDrigon\MetaYaml\MetaYaml;
use Seld\JsonLint\JsonParser;
use Seld\JsonLint\ParsingException;
use Composer\Util\RemoteFilesystem;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads/writes Yaml files.
 *
 */
class YamlFile
{
    const LAX_SCHEMA = 1;
    const STRICT_SCHEMA = 2;

    const JSON_UNESCAPED_SLASHES = 64;
    const JSON_PRETTY_PRINT = 128;
    const JSON_UNESCAPED_UNICODE = 256;

    const COMPOSER_SCHEMA_PATH = '/../../../res/composer-schema.json';

    private $path;
    private $rfs;
    private $io;

    /**
     * Initializes YAML file reader/parser.
     *
     * @param  string                    $path path to a lockfile
     * @param  RemoteFilesystem          $rfs  required for loading http/https YAML files
     * @param  IOInterface               $io
     * @throws \InvalidArgumentException
     */
    public function __construct($path, RemoteFilesystem $rfs = null, IOInterface $io = null)
    {
        $this->path = $path;

        if (null === $rfs && preg_match('{^https?://}i', $path)) {
            throw new \InvalidArgumentException('http urls require a RemoteFilesystem instance to be passed');
        }
        $this->rfs = $rfs;
        $this->io = $io;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Checks whether YAML file exists.
     *
     * @return bool
     */
    public function exists()
    {
        return is_file($this->path);
    }

    /**
     * Reads json file.
     *
     * @throws \RuntimeException
     * @return mixed
     */
    public function read()
    {
        try {
            if ($this->rfs) {
                $yaml = $this->rfs->getContents($this->path, $this->path, false);
            } else {
                if ($this->io && $this->io->isDebug()) {
                    $this->io->writeError('Reading ' . $this->path);
                }
                $yaml = file_get_contents($this->path);
            }
        } catch (TransportException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not read '.$this->path."\n\n".$e->getMessage());
        }

        return static::parseYaml($yaml, $this->path);
    }

    /**
     * Writes json file.
     *
     * @param  array                                $hash    writes hash into json file
     * @param  int                                  $options json_encode options (defaults to JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
     * @throws \UnexpectedValueException|\Exception
     */
    public function write(array $hash, $options = 448)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new \UnexpectedValueException(
                    $dir.' exists and is not a directory.'
                );
            }
            if (!@mkdir($dir, 0777, true)) {
                throw new \UnexpectedValueException(
                    $dir.' does not exist and could not be created.'
                );
            }
        }

        $retries = 3;
        while ($retries--) {
            try {
                file_put_contents($this->path, static::encode($hash, $options). ($options & self::JSON_PRETTY_PRINT ? "\n" : ''));
                break;
            } catch (\Exception $e) {
                if ($retries) {
                    usleep(500000);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Validates the schema of the current json file according to composer-schema.json rules
     *
     * @param  int                     $schema a JsonFile::*_SCHEMA constant
     * @param  string|null             $schemaFile a path to the schema file
     * @throws JsonValidationException
     * @return bool                    true on success
     */
    public function validateSchema($schema = self::STRICT_SCHEMA, $schemaFile = null)
    {
        $content = file_get_contents($this->path);
        $data = Yaml::parse($content);

        if (null === $data && 'null' !== $content) {
            self::validateSyntax($content, $this->path);
        }

        if (null === $schemaFile) {
            $schemaFile = __DIR__ . self::COMPOSER_SCHEMA_PATH;
        }

        // Prepend with file:// only when not using a special schema already (e.g. in the phar)
        if (false === strpos($schemaFile, '://')) {
            $schemaFile = 'file://' . $schemaFile;
        }

        $schemaData = (object) array('$ref' => $schemaFile);

        if ($schema === self::LAX_SCHEMA) {
            $schemaData->additionalProperties = true;
            $schemaData->required = array();
        }

        /*
        //FIXME: Implement later

        $schemaLoader = new JsonLoader();
        $schemaData = $schemaLoader->loadFromFile($schemaFile);
        $validator = new MetaYaml($schemaData);
        $validator->validate($data);
        */

        return true;
    }

    /**
     * Encodes an array into (optionally pretty-printed) YAML
     *
     * @param  mixed  $data    Data to encode into a formatted YAML string
     * @param  int    $options json_encode options (defaults to JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
     * @return string Encoded json
     */
    public static function encode($data, $options = 448)
    {
        $yaml = Yaml::dump($data, 4);

        $prettyPrint = (bool) ($options & self::JSON_PRETTY_PRINT);
        $unescapeUnicode = (bool) ($options & self::JSON_UNESCAPED_UNICODE);
        $unescapeSlashes = (bool) ($options & self::JSON_UNESCAPED_SLASHES);

        if (!$prettyPrint && !$unescapeUnicode && !$unescapeSlashes) {
            return $yaml;
        }

        return $yaml;//JsonFormatter::format($json, $unescapeUnicode, $unescapeSlashes);
    }

    /**
     * Parses YAML string and returns hash.
     *
     * @param string $yaml json string
     * @param string $file the json file
     *
     * @return mixed
     */
    public static function parseYaml($yaml, $file = null)
    {
        if (null === $yaml) {
            return;
        }

        $data = Yaml::parse($yaml);

        return $data;
    }

    /**
     * Validates the syntax of a Yaml string
     *
     * @param  string                    $yaml
     * @param  string                    $file
     * @throws \UnexpectedValueException
     * @throws ParsingException
     * @return bool                      true on success
     */
    protected static function validateSyntax($yaml, $file = null)
    {
        return true;
    }
}
