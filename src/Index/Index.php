<?php
declare(strict_types = 1);

namespace LanguageServer\Index;

use LanguageServer\Definition;
use Sabre\Event\EmitterTrait;

/**
 * Represents the index of a project or dependency
 * Serializable for caching
 */
class Index implements ReadableIndex, \Serializable
{
    use EmitterTrait;

    /**
     * An associative array that maps fully qualified symbol names to Definitions (global or not)
     *
     * @var Definition[]
     */
    private $definitions = [];

    /**
     * An associative array that maps fully qualified symbol names to global Definitions
     *
     * @var Definition[]
     */
    private $globalDefinitions = [];

    /**
     * An associative array that maps namespaces to an associative array of FQN to Definitions
     *
     * @var Definition[]
     */
    private $namespaceDefinitions = [];

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
     * to Definitions (global or not)
     *
     * @return Definition[]
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Returns an associative array [string => Definition] that maps fully qualified symbol names
     * to global Definitions
     *
     * @return Definition[]
     */
    public function getGlobalDefinitions(): array
    {
        return $this->globalDefinitions;
    }

    /**
     * Returns the Definitions that are in the given namespace
     *
     * @param string $namespace
     * @return Definitions[]
     */
    public function getDefinitionsForNamespace(string $namespace): array
    {
        return isset($this->namespaceDefinitions[$namespace])
            ? $this->namespaceDefinitions[$namespace]
            : []
        ;
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
        $this->definitions[$fqn] = $definition;
        $this->setGlobalDefinition($fqn, $definition);
        $this->setNamespaceDefinition($fqn, $definition);
        $this->emit('definition-added');
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
        unset($this->globalDefinitions[$fqn]);
        unset($this->references[$fqn]);
        $this->removeNamespaceDefinition($fqn);
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

        foreach ($this->definitions as $fqn => $definition) {
            $this->setGlobalDefinition($fqn, $definition);
            $this->setNamespaceDefinition($fqn, $definition);
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

    /**
     * Registers a definition to the global definitions index if it is global
     *
     * @param string $fqn The fully qualified name of the symbol
     * @param Definition $definition The Definition object
     * @return void
     */
    private function setGlobalDefinition(string $fqn, Definition $definition)
    {
        if ($definition->isGlobal) {
            $this->globalDefinitions[$fqn] = $definition;
        }
    }

    /**
     * Registers a definition to a namespace
     *
     * @param string $fqn The fully qualified name of the symbol
     * @param Definition $definition The Definition object
     * @return void
     */
    private function setNamespaceDefinition(string $fqn, Definition $definition)
    {
        $namespace = $this->extractNamespace($fqn);
        if (!isset($this->namespaceDefinitions[$namespace])) {
            $this->namespaceDefinitions[$namespace] = [];
        }
        $this->namespaceDefinitions[$namespace][$fqn] = $definition;
    }

    /**
     * Removes a definition from a namespace
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return void
     */
    private function removeNamespaceDefinition(string $fqn)
    {
        $namespace = $this->extractNamespace($fqn);
        if (isset($this->namespaceDefinitions[$namespace])) {
            unset($this->namespaceDefinitions[$namespace][$fqn]);

            if (0 === sizeof($this->namespaceDefinitions[$namespace])) {
                unset($this->namespaceDefinitions[$namespace]);
            }
        }
    }

    /**
     * @param string $fqn
     * @return string The namespace extracted from the given FQN
     */
    private function extractNamespace(string $fqn): string
    {
        foreach (['::', '->'] as $operator) {
            if (false !== ($pos = strpos($fqn, $operator))) {
                return substr($fqn, 0, $pos);
            }
        }

        return $fqn;
    }
}
