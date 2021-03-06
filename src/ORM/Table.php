<?php

namespace Hayko\Mongodb\ORM;

use ArrayObject;
use BadMethodCallException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\ORM\Exception\MissingEntityException;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table as CakeTable;
use Hayko\Mongodb\ORM\Behavior\SchemalessBehavior;
use RuntimeException;

class Table extends CakeTable
{

    /**
     * return MongoCollection object
     *
     * @return MongoCollection
     * @access private
     */
    private function __getCollection()
    {
        $driver = $this->connection()->driver();
        $collection = $driver->getCollection($this->table());

        return $collection;
    }

    /**
     * always return true because mongo is schemaless
     *
     * @param string $field
     * @return bool
     * @access public
     */
    public function hasField($field)
    {
        return true;
    }

    /**
     * find documents
     *
     * @param string $type
     * @param array $options
     * @return MongoQuery|Cake\ORM\Entity
     * @access public
     */
    public function find($type = 'all', $options = [])
    {
        $query = new MongoFinder($this->__getCollection(), $options);
        $method = 'find' . ucfirst($type);
        if (method_exists($query, $method)) {
            $mongoCursor = $query->{$method}();
            $results = new ResultSet($mongoCursor, $this->alias());

            if (isset($options['whitelist'])) {
                return new MongoQuery($results->toArray(), $query->count());
            } else {
                return $results->toArray();
            }
        }

        throw new BadMethodCallException(
            sprintf('Unknown method "%s"', $method)
        );
    }

    /**
     * get the document by _id
     *
     * @param string $primaryKey
     * @param array $options
     * @return Cake\ORM\Entity
     * @access public
     */
    public function get($primaryKey, $options = [])
    {
        $query = new MongoFinder($this->__getCollection(), $options);
        $mongoCursor = $query->get($primaryKey);

        //if find document, convert to cake entity
        if ($mongoCursor->count()) {
            $document = new Document(current(iterator_to_array($mongoCursor)), $this->alias());
            return $document->cakefy();
        }

        throw new InvalidPrimaryKeyException(sprintf(
            'Record not found in table "%s" with primary key [%s]',
            $this->_table->table(),
            $primaryKey
        ));
    }

    /**
     * remove one document
     *
     * @param Cake\Datasource\EntityInterface $entity
     * @param array $options
     * @return bool
     * @access public
     */
    public function delete(EntityInterface $entity, $options = [])
    {
        try {
            $collection = $this->__getCollection();
            $success = $collection->remove(['_id' => new \MongoId($entity->_id)]);
        } catch (\MongoException $e) {
            trigger_error($e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * save the document
     *
     * @param \Cake\ORM\Entity $entity
     * @param array $options
     * @return mixed $success
     * @access public
     */
    public function save(EntityInterface $entity, $options = [])
    {
        $options = new ArrayObject($options + [
            'checkRules' => true,
            'checkExisting' => true,
            '_primary' => true
        ]);

        if ($entity->errors()) {
            return false;
        }

        if ($entity->isNew() === false && !$entity->dirty()) {
            return $entity;
        }

        $success = $this->_processSave($entity, $options);
        if ($success) {
            if ($options['_primary']) {
                $this->dispatchEvent('Model.afterSaveCommit', compact('entity', 'options'));
                $entity->isNew(false);
                $entity->source($this->registryAlias());
            }
        }

        return $success;
    }

    /**
     * insert or update the document
     *
     * @param \Cake\ORM\Entity $entity
     * @param array $options
     * @return mixed $success
     * @access protected
     */
    protected function _processSave($entity, $options)
    {
        $mode = $entity->isNew() ? RulesChecker::CREATE : RulesChecker::UPDATE;
        if ($options['checkRules'] && !$this->checkRules($entity, $mode, $options)) {
            return false;
        }

        $event = $this->dispatchEvent('Model.beforeSave', compact('entity', 'options'));
        if ($event->isStopped()) {
            return $event->result;
        }

        $data = $entity->toArray();
        $isNew = $entity->isNew();

        //convert to mongodate
        if (isset($data['created'])) {
            $data['created']  = new \MongoDate(strtotime($data['created']->toDateTimeString()));
        }
        if (isset($data['modified'])) {
            $data['modified'] = new \MongoDate(strtotime($data['modified']->toDateTimeString()));
        }

        if ($isNew) {
            $success = $this->_insert($entity, $data);
        } else {
            $success = $this->_update($entity, $data);
        }

        if ($success) {
            $this->dispatchEvent('Model.afterSave', compact('entity', 'options'));
            $entity->clean();
            if (!$options['_primary']) {
                $entity->isNew(false);
                $entity->source($this->registryAlias());
            }

            $success = true;
        }

        if (!$success && $isNew) {
            $entity->unsetProperty($this->primaryKey());
            $entity->isNew(true);
        }

        if ($success) {
            return $entity;
        }

        return false;
    }

    /**
     * insert new document
     *
     * @param \Cake\ORM\Entity $entity
     * @param array $data
     * @return mixed $success
     * @access protected
     */
    protected function _insert($entity, $data)
    {
        $primary = (array)$this->primaryKey();
        if (empty($primary)) {
            $msg = sprintf(
                'Cannot insert row in "%s" table, it has no primary key.',
                $this->table()
            );
            throw new RuntimeException($msg);
        }
        $primary = ['_id' => $this->_newId($primary)];

        $filteredKeys = array_filter($primary, 'strlen');
        $data = $data + $filteredKeys;

        $success = false;
        if (empty($data)) {
            return $success;
        }

        $success = $entity;
        $collection = $this->__getCollection();

        if (is_object($collection)) {
            $r = $collection->insert($data);
            if ($r['ok'] == false) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * update one document
     *
     * @param \Cake\ORM\Entity $entity
     * @param array $data
     * @return mixed $success
     * @access protected
     */
    protected function _update($entity, $data)
    {
        unset($data['_id']);

        $success = $entity;
        $collection = $this->__getCollection();

        if (is_object($collection)) {
            $r = $collection->update(['_id' => new \MongoId($entity->_id)], $data);
            if ($r['ok'] == false) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * create new MongoId
     *
     * @param mixed $primary
     * @return MongoId
     * @access public
     */
    protected function _newId($primary)
    {
        if (!$primary || count((array)$primary) > 1) {
            return null;
        }

        return new \MongoId();
    }
}
