<?php
declare(strict_types = 1);

namespace LanguageServer\Index;

use LanguageServer\Definition;
use Sabre\Event\EmitterTrait;
use Tries\RadixTrie;

/**
 * Represents the index of a project or dependency
 * Serializable for caching
 */
class Index implements ReadableIndex, \Serializable
{
    use EmitterTrait;

    /**
     * An associative array that maps fully qualified symbol names to Definitions
     *
     * @var Definition[]
     */
    private $definitions = [];

    /**
     * A radix tree to store fqn and definitions
     *
     * @var RadixTrie
     */
    private $radixTrie;

    /**
     * An associative array that maps fully qualified symbol names to arrays of document URIs that reference the symbol
     *
     * @var string[][]
     */
    private $references = [];

    /**
     * @var bool
     */
    private $complete = false;

    /**
     * @var bool
     */
    private $staticComplete = false;

    public function __construct()
    {
        $this->radixTrie = new RadixTrie();
    }

    /**
     * Marks this index as complete
     *
     * @return void
     */
    public function setComplete()
    {
        if (!$this->isStaticComplete()) {
            $this->setStaticComplete();
        }
        $this->complete = true;
        $this->emit('complete');
    }

    /**
     * Marks this index as complete for static definitions and references
     *
     * @return void
     */
    public function setStaticComplete()
    {
        $this->staticComplete = true;
        $this->emit('static-complete');
    }

    /**
     * Returns true if this index is complete
     *
     * @return bool
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Returns true if this index is complete
     *
     * @return bool
     */
    public function isStaticComplete(): bool
    {
        return $this->staticComplete;
    }

    /**
     * Returns an associative array [string => Definition] that maps fully qualified symbol names
     * to Definitions
     *
     * @return Definition[]
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Returns the Definition object by a specific FQN
     *
     * @param string $fqn
     * @param bool $globalFallback Whether to fallback to global if the namespaced FQN was not found
     * @return Definition|null
     */
    public function getDefinition(string $fqn, bool $globalFallback = false)
    {
        if (isset($this->definitions[$fqn])) {
            return $this->definitions[$fqn];
        }
        if ($globalFallback) {
            $parts = explode('\\', $fqn);
            $fqn = end($parts);
            return $this->getDefinition($fqn);
        }
    }

    /**
     * Registers a definition
     *
     * @param string $fqn The fully qualified name of the symbol
     * @param Definition $definition The Definition object
     * @return void
     */
    public function setDefinition(string $fqn, Definition $definition)
    {
        if (!empty($fqn)) {
            $this->radixTrie->add($fqn);
        }
        $this->definitions[$fqn] = $definition;
        $this->emit('definition-added');
    }
    
    public function findWithPrefix($prefix)
    {
        if (empty($prefix)) {
            return $this->definitions;
        }

        $fqns = $this->radixTrie->search($prefix);
        $definitions = [];

        foreach ($fqns as $fqn => $ignored) {
            if (!isset($this->definitions[$fqn])) {
                continue;
            }
            $definitions[$fqn] = $this->definitions[$fqn];
        }

        return $definitions;
    }

    /**
     * Unsets the Definition for a specific symbol
     * and removes all references pointing to that symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return void
     */
    public function removeDefinition(string $fqn)
    {
        unset($this->definitions[$fqn]);
        unset($this->references[$fqn]);
    }

    /**
     * Returns all URIs in this index that reference a symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return string[]
     */
    public function getReferenceUris(string $fqn): array
    {
        return $this->references[$fqn] ?? [];
    }

    /**
     * For test use.
     * Returns all references, keyed by fqn.
     *
     * @return string[][]
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    /**
     * Adds a document URI as a referencee of a specific symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return void
     */
    public function addReferenceUri(string $fqn, string $uri)
    {
        if (!isset($this->references[$fqn])) {
            $this->references[$fqn] = [];
        }
        // TODO: use DS\Set instead of searching array
        if (array_search($uri, $this->references[$fqn], true) === false) {
            $this->references[$fqn][] = $uri;
        }
    }

    /**
     * Removes a document URI as the container for a specific symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @param string $uri The URI
     * @return void
     */
    public function removeReferenceUri(string $fqn, string $uri)
    {
        if (!isset($this->references[$fqn])) {
            return;
        }
        $index = array_search($fqn, $this->references[$fqn], true);
        if ($index === false) {
            return;
        }
        array_splice($this->references[$fqn], $index, 1);
    }

    /**
     * @param string $serialized
     * @return void
     */
    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        foreach ($data as $prop => $val) {
            $this->$prop = $val;
        }

        $this->radixTrie = new RadixTrie;
        $fqns = array_keys($this->definitions);
        shuffle($fqns);
        foreach ($fqns as $fqn) {
            $this->radixTrie->add($fqn);
        }
    }

    /**
     * @param string $serialized
     * @return string
     */
    public function serialize()
    {
        return serialize([
            'definitions' => $this->definitions,
            'references' => $this->references,
            'complete' => $this->complete,
            'staticComplete' => $this->staticComplete
        ]);
    }
}
