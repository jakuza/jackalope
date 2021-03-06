<?php
namespace Jackalope\NodeType;

use Jackalope\ObjectManager, Jackalope\NotImplementedException;
use ArrayIterator;

/**
 * Allows for the retrieval and (in implementations that support it) the
 * registration of node types. Accessed via Workspace.getNodeTypeManager().
 *
 * Implementation:
 * We try to do lazy fetching of node types.
 */
class NodeTypeManager implements \IteratorAggregate, \PHPCR\NodeType\NodeTypeManagerInterface
{
    /**
     * The factory to instantiate objects
     * @var Factory
     */
    protected $factory;

    protected $objectManager;

    protected $primaryTypes;
    protected $mixinTypes;

    protected $nodeTree = array();

    /**
     * Flag to only load all node types from the backend once.
     *
     * methods like hasNodeType need to fetch all node types.
     * others like getNodeType do not need all, but just the requested one.
     */
    protected $fetchedAllFromBackend = false;

    public function __construct($factory, ObjectManager $objectManager)
    {
        $this->factory = $factory;
        $this->objectManager = $objectManager;
    }

    /**
     * Fetch a node type from backend.
     * Without a filter parameter, this will fetch all node types from the backend.
     *
     * It is no problem to trigger the fetch all multiple times, fetch all will occur
     * only once.
     * This will not attempt to overwrite existing node types.
     *
     * @param namelist string type name to fetch. defaults to null which will fetch all nodes.
     * @return void
     */
    protected function fetchNodeTypes($name = null)
    {
        if ($this->fetchedAllFromBackend) return;

        if (! is_null($name)) {
            if (empty($this->primaryTypes[$name]) &&
                empty($this->mixinTypes[$name])) {
                //OPTIMIZE: also avoid trying to fetch nonexisting definitions we already tried to get
                $dom = $this->objectManager->getNodeType($name);
            } else {
                return; //we already know this node
            }
        } else {
            $dom = $this->objectManager->getNodeTypes();
            $this->fetchedAllFromBackend = true;
        }

        $xp = new \DOMXpath($dom);
        $nodetypes = $xp->query('/nodeTypes/nodeType');
        foreach ($nodetypes as $nodetype) {
            $nodetype = $this->factory->get('NodeType\NodeType', array($this, $nodetype));
            $name = $nodetype->getName();
            //do not overwrite existing types. maybe they where changed locally
            if (empty($this->primaryTypes[$name]) &&
                empty($this->mixinTypes[$name])) {
                $this->addNodeType($nodetype);
            }
        }
    }

    /**
     * Stores the node type in our internal structures (flat && tree)
     *
     * @param   \PHPCR\NodeType\NodeTypeInterface  $nodetype   The nodetype to add
     */
    protected function addNodeType(\PHPCR\NodeType\NodeTypeInterface $nodetype)
    {
        if ($nodetype->isMixin()) {
            $this->mixinTypes[$nodetype->getName()] = $nodetype;
        } else {
            $this->primaryTypes[$nodetype->getName()] = $nodetype;
        }
        $this->addToNodeTree($nodetype);
    }

    /**
     * Returns the declared subnodes of a given nodename
     * @param string Nodename
     * @return array of strings with the names of the subnodes
     */
    public function getDeclaredSubtypes($nodeTypeName)
    {
        if (empty($this->nodeTree[$nodeTypeName])) {
            return array();
        }
        return $this->nodeTree[$nodeTypeName];
    }

    /**
     * Returns the subnode hirarchie of a given nodename
     * @param string Nodename
     * @return array of strings with the names of the subnodes
     */
    public function getSubtypes($nodeTypeName)
    {
        $ret = array();
        if (empty($this->nodeTree[$nodeTypeName])) {
            return array();
        }

        foreach ($this->nodeTree[$nodeTypeName] as $subnode) {
            $ret = array_merge($ret, array($subnode), $this->getDeclaredSubtypes($subnode));
        }
        return $ret;
    }

    /**
     * Adds a node to the tree to get the subnodes later on
     * @param NodeType the nodetype to add
     */
    protected function addToNodeTree($nodetype)
    {
        foreach ($nodetype->getDeclaredSupertypeNames() as $declaredSupertypeName) {
            if (isset($this->nodeTree[$declaredSupertypeName])) {
                $this->nodeTree[$declaredSupertypeName] = array_merge($this->nodeTree[$declaredSupertypeName], array($nodetype->getName()));
            } else {
                $this->nodeTree[$declaredSupertypeName] = array($nodetype->getName());
            }
        }
    }

    /**
     * Returns the named node type.
     *
     * @param string $nodeTypeName the name of an existing node type.
     * @return \PHPCR\NodeType\NodeTypeInterface A NodeType object.
     * @throws \PHPCR\NodeType\NoSuchNodeTypeException if no node type by the given name exists.
     * @throws \PHPCR\RepositoryException if another error occurs.
     */
    public function getNodeType($nodeTypeName)
    {
        $this->fetchNodeTypes($nodeTypeName);

        if (isset($this->primaryTypes[$nodeTypeName])) {
            return $this->primaryTypes[$nodeTypeName];
        } elseif (isset($this->mixinTypes[$nodeTypeName])) {
            return $this->mixinTypes[$nodeTypeName];
        } else {
            if (is_null($nodeTypeName)) $nodeTypeName = 'nodeTypeName was <null>';
            throw new \PHPCR\NodeType\NoSuchNodeTypeException($nodeTypeName);
        }
    }

    /**
     * Returns true if a node type with the specified name is registered. Returns
     * false otherwise.
     *
     * @param string $name - a String.
     * @return boolean a boolean
     * @throws \PHPCR\RepositoryException if an error occurs.
     */
    public function hasNodeType($name)
    {
        $this->fetchNodeTypes($name);
        return isset($this->primaryTypes[$name]) || isset($this->mixinTypes[$name]);
    }

    /**
     * Returns an iterator over all available node types (primary and mixin).
     *
     * @return Iterator implementing SeekableIterator and Countable. Keys are the node type names, values the corresponding NodeTypeInterface instances.
     * @throws \PHPCR\RepositoryException if an error occurs.
     */
    public function getAllNodeTypes()
    {
        $this->fetchNodeTypes();
        return new ArrayIterator(array_values(array_merge($this->primaryTypes, $this->mixinTypes)));
    }

    /**
     * Returns an iterator over all available primary node types.
     *
     * @return Iterator implementing SeekableIterator and Countable. Keys are the node type names, values the corresponding NodeTypeInterface instances.
     * @throws \PHPCR\RepositoryException if an error occurs.
     */
    public function getPrimaryNodeTypes()
    {
        $this->fetchNodeTypes();
        return new ArrayIterator(array_values($this->primaryTypes));
    }

    /**
     * Returns an iterator over all available mixin node types. If none are available,
     * an empty iterator is returned.
     *
     * @return Iterator implementing SeekableIterator and Countable. Keys are the node type names, values the corresponding NodeTypeInterface instances.
     * @throws \PHPCR\RepositoryException if an error occurs.
     */
    public function getMixinNodeTypes()
    {
        $this->fetchNodeTypes();
        return new ArrayIterator(array_values($this->mixinTypes));
    }

    /**
     * Returns an empty NodeTypeTemplate which can then be used to define a node type
     * and passed to NodeTypeManager.registerNodeType.
     *
     * If $ntd is given:
     * Returns a NodeTypeTemplate holding the specified node type definition. This
     * template can then be altered and passed to NodeTypeManager.registerNodeType.
     *
     * @param \PHPCR\NodeType\NodeTypeDefinitionInterface $ntd a NodeTypeDefinition.
     * @return \PHPCR\NodeType\NodeTypeTemplateInterface A NodeTypeTemplate.
     * @throws \PHPCR\UnsupportedRepositoryOperationException if this implementation does not support node type registration.
     * @throws \PHPCR\RepositoryException if another error occurs.
     */
    public function createNodeTypeTemplate($ntd = NULL)
    {
       return $this->factory->get('NodeType\NodeTypeTemplate', array($this, $ntd));
    }

    /**
     * Returns an empty NodeDefinitionTemplate which can then be used to create a
     * child node definition and attached to a NodeTypeTemplate.
     *
     * @return \PHPCR\NodeType\NodeDefinitionTemplateInterface A NodeDefinitionTemplate.
     * @throws \PHPCR\UnsupportedRepositoryOperationException if this implementation does not support node type registration.
     * @throws \PHPCR\RepositoryException if another error occurs.
     */
    public function createNodeDefinitionTemplate()
    {
       return $this->factory->get('NodeType\NodeDefinitionTemplate', array($this));
    }

    /**
     * Returns an empty PropertyDefinitionTemplate which can then be used to create
     * a property definition and attached to a NodeTypeTemplate.
     *
     * @return \PHPCR\NodeType\PropertyDefinitionTemplateInterface A PropertyDefinitionTemplate.
     * @throws \PHPCR\UnsupportedRepositoryOperationException if this implementation does not support node type registration.
     * @throws \PHPCR\RepositoryException if another error occurs.
     */
    public function createPropertyDefinitionTemplate()
    {
       return $this->factory->get('NodeType\PropertyDefinitionTemplate', array($this));
    }

    /**
     * Registers a new node type or updates an existing node type using the specified
     * definition and returns the resulting NodeType object.
     * Typically, the object passed to this method will be a NodeTypeTemplate (a
     * subclass of NodeTypeDefinition) acquired from NodeTypeManager.createNodeTypeTemplate
     * and then filled-in with definition information.
     *
     * @param \PHPCR\NodeType\NodeTypeDefinitionInterface $ntd an NodeTypeDefinition.
     * @param boolean $allowUpdate a boolean
     * @return \PHPCR\NodeType\NodeTypeInterface the registered node type
     * @throws \PHPCR\InvalidNodeTypeDefinitionException if the NodeTypeDefinition is invalid.
     * @throws \PHPCR\NodeType\NodeTypeExistsException if allowUpdate is false and the NodeTypeDefinition specifies a node type name that is already registered.
     * @throws \PHPCR\UnsupportedRepositoryOperationException if this implementation does not support node type registration.
     * @throws \PHPCR\RepositoryException if another error occurs.
     */
    public function registerNodeType(\PHPCR\NodeType\NodeTypeDefinitionInterface $ntd, $allowUpdate)
    {
        $nt = $this->createNodeType($ntd, $allowUpdate);
        $this->addNodeType($nt);
        return $nt;
    }

    /**
     * Creates a NodeType from a NodeTypeDefinition and validates it
     *
     * @param   \PHPCR\NodeType\NodeTypeDefinitionInterface  $ntd    The node type definition
     * @param   bool    $allowUpdate    Whether an existing note type can be updated
     * @throws \PHPCR\NodeType\NodeTypeExistsException   If the node type is already existing and allowUpdate is false
     */
    protected function createNodeType(\PHPCR\NodeType\NodeTypeDefinitionInterface $ntd, $allowUpdate)
    {
        if ($this->hasNodeType($ntd->getName()) && !$allowUpdate) {
            throw new \PHPCR\NodeType\NodeTypeExistsException('NodeType already existing: '.$ntd->getName());
        }
        return $this->factory->get('NodeType\NodeType', array($this, $ntd));
    }
    /**
     * Registers or updates the specified array of NodeTypeDefinition objects.
     * This method is used to register or update a set of node types with mutual
     * dependencies. Returns an iterator over the resulting NodeType objects.
     * The effect of the method is "all or nothing"; if an error occurs, no node
     * types are registered or updated.
     *
     * @param array $definitions an array of NodeTypeDefinitions
     * @param boolean $allowUpdate a boolean
     * @return Iterator over the registered node types implementing SeekableIterator and Countable. Keys are the node type names, values the corresponding NodeTypeInterface instances.
     * @return \PHPCR\NodeType\NodeTypeIteratorInterface the registered node types.
     * @throws \PHPCR\InvalidNodeTypeDefinitionException - if a NodeTypeDefinition within the Collection is invalid or if the Collection contains an object of a type other than NodeTypeDefinition.
     * @throws \PHPCR\NodeType\NodeTypeExistsException if allowUpdate is false and a NodeTypeDefinition within the Collection specifies a node type name that is already registered.
     * @throws \PHPCR\UnsupportedRepositoryOperationException if this implementation does not support node type registration.
     * @throws \PHPCR\RepositoryException if another error occurs.
     */
    public function registerNodeTypes(array $definitions, $allowUpdate)
    {
        $nts = array();
        // prepare them first (all or nothing)
        foreach ($definitions as $definition) {
            $nts[] = $this->createNodeType($ntd, $allowUpdate);
        }
        foreach ($nts as $nt) {
            $this->addNodeType($nt);
        }
        return new ArrayIterator($nts);
    }

    /**
     * Unregisters the specified node type.
     *
     * @param string $name a String.
     * @return void
     * @throws \PHPCR\UnsupportedRepositoryOperationException if this implementation does not support node type registration.
     * @throws \PHPCR\NodeType\NoSuchNodeTypeException if no registered node type exists with the specified name.
     * @throws \PHPCR\RepositoryException if another error occurs.
     */
    public function unregisterNodeType($name)
    {
        if (!empty($this->primaryTypes[$name])) {
            unset($this->primaryTypes[$name]);
        } elseif (!empty($this->mixinTypes[$name])) {
            unset($this->mixinTypes[$name]);
        } else {
            throw new \PHPCR\NodeType\NoSuchNodeTypeException('NodeType not found: '.$name);
        }
        // TODO remove from nodeTree
        throw new NotImplementedException();
    }

    /**
     * Unregisters the specified set of node types. Used to unregister a set of node
     * types with mutual dependencies.
     *
     * @param array $names a String array
     * @return void
     * @throws \PHPCR\UnsupportedRepositoryOperationException if this implementation does not support node type registration.
     * @throws \PHPCR\NodeType\NoSuchNodeTypeException if one of the names listed is not a registered node type.
     * @throws \PHPCR\RepositoryException if another error occurs.
     */
    public function unregisterNodeTypes(array $names)
    {
        foreach ($names as $name) {
            $this->unregisterNodeType($name);
        }
    }

    /**
     * Provide Traversable interface: redirect to getAllNodeTypes
     *
     * @return Iterator over all node types
     */
    public function getIterator() {
        return $this->getAllNodeTypes();
    }
}
