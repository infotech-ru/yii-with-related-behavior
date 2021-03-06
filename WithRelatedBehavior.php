<?php
/**
 * WithRelatedBehavior class file.
 *
 * @author Alexander Kochetov <creocoder@gmail.com>
 * @link https://github.com/yiiext/with-related-behavior
 */

/**
 * Allows you to save related models along with the main model.
 * All relation types are supported.
 * You may set additional relation attributes for MANY_MANY relations.
 *
 * @property CActiveRecord $owner
 * @method CActiveRecord getOwner()
 *
 * @package yiiext.with-related-behavior
 */
class WithRelatedBehavior extends CActiveRecordBehavior
{
	/**
	 * List of relations to process (save/validate)
	 * @var array
	 */
	private $_processedRelations = array();

	/**
	 * Relations attributes values
	 * @var array
	 */
	private $relationAttributes = array();
	
	/**
	 * Validation errors
	 * @var array
	 */
	private $_errors = array();
	private $_alreadySaved = array();

	/**
	 * Returns the errors
	 * @return array errors. Empty array is returned if no error.
	 */
	public function getErrors()
	{
		return $this->_errors;
	}

	/**
	 * Validate main model and all it's related models recursively.
	 * @param array $data attributes and relations.
	 * @param boolean $clearErrors whether to call {@link CModel::clearErrors} before performing validation.
	 * @param CActiveRecord $owner for internal needs.
	 * @return boolean whether the validation is successful without any error.
	 */
	public function validate($data=null,$clearErrors=true,$owner=null)
	{
		$data=CMap::mergeArray($this->_processedRelations,is_array($data)?$data:array());
		if($clearErrors)
			$this->internalClearErrors($data,$owner);
		$this->_errors=$this->internalValidateAndGetErrors($data,$owner);
		return !$this->_errors;
	}

	/**
	 * @param array|string $foreignKey
	 * @param CDbTableSchema $ownerTableSchema
	 * @param CDbTableSchema $dependentTableSchema
	 * @return array
	 */
	protected function getDependencyAttributes($foreignKey, $ownerTableSchema, $dependentTableSchema)
	{
		$dbSchema = $this->getOwner()->getDbConnection()->getSchema();
		$map = array();

		if(is_string($foreignKey))
			$foreignKey=preg_split('/\s*,\s*/',$foreignKey,-1,PREG_SPLIT_NO_EMPTY);

		foreach($foreignKey as $fk=>$pk)
		{
			if(is_int($fk))
			{
				$index = $fk;
				$fk = $pk;

				if(isset($dependentTableSchema->foreignKeys[$fk])
							&& $dbSchema->compareTableNames(
								$ownerTableSchema->rawName,
								$dependentTableSchema->foreignKeys[$fk][0]
							)
						)
					$pk=$dependentTableSchema->foreignKeys[$fk][1];
				else // FK constraints undefined
				{
					if(is_array($ownerTableSchema->primaryKey)) // composite PK
						$pk=$ownerTableSchema->primaryKey[$index];
					else
						$pk=$ownerTableSchema->primaryKey;
				}
			}
			$map[$fk] = $pk;
		}
		return $map;
	}

	/**
	 * Sets relation attributes of many to many relation
	 *
	 * @param string $relationName
	 * @param object $relatedObject
	 * @param array $attributes [attributeName => value, ...]
	 * @throws CException if $relationName is not MANY_MANY relation
	 * @throws CException if $relatedObject is not in the $relationName objects list
	 */
	public function setManyManyAttributes($relationName,$relatedObject,$attributes)
	{
		$objHash=spl_object_hash($relatedObject);
		if(!isset($this->relationAttributes[$relationName][$objHash]))
			$this->initManyManyAttributes($relationName,$relatedObject);

		$merged=array_merge($this->relationAttributes[$relationName][$objHash],$attributes);
		$this->relationAttributes[$relationName][$objHash]=$merged;
	}

	/**
	 * Gets relation attribute of many to many relation
	 *
	 * @param string $relationName
	 * @param object $relatedObject
	 * @throws CException if $relationName is not MANY_MANY relation
	 * @throws CException if $relatedObject is not in the $relationName objects list
	 */
	public function getManyManyAttributes($relationName,$relatedObject)
	{
		$objHash=spl_object_hash($relatedObject);
		if(!isset($this->relationAttributes[$relationName][$objHash]))
			$this->initManyManyAttributes($relationName,$relatedObject);

		return $this->relationAttributes[$relationName][$objHash];
	}

	/**
	 * Gets relation attribute of many to many relation
	 *
	 * @param string $relationName
	 * @param object $relatedObject
	 * @param string $attributeName
	 * @throws CException if $relationName is not MANY_MANY relation
	 * @throws CException if $relatedObject is not in the $relationName objects list
	 */
	public function getManyManyAttribute($relationName,$relatedObject,$attributeName)
	{
		return $this->getManyManyAttributes($relationName,$relatedObject)[$attributeName];
	}

	/**
	 * Save main model and all it's related models recursively.
	 * @param bool $runValidation whether to perform validation before saving the record.
	 * @param array $data attributes and relations.
	 * @param CActiveRecord $owner for internal needs.
	 * @return boolean whether the saving succeeds.
	 * @throws CDbException
	 * @throws Exception
	 */
	public function save($runValidation=true,$data=null,$owner=null)
	{
		if($owner===null)
		{
			if($runValidation && !$this->validate($data))
				return false;

			$owner=$this->getOwner();
			$data=CMap::mergeArray($this->_processedRelations,is_array($data)?$data:array());

			$this->_alreadySaved=array();
		}
		else
		{
			$thisClass=__CLASS__;
			foreach ($owner->behaviors() as $behName=>$behDefinition)
				if (
					is_object($behDefinition)&&($behDefinition instanceof $thisClass)
						||!is_object($behDefinition)&&$this->behaviorClassSubclassOrSelf($behDefinition,$thisClass)
				)
					$data=CMap::mergeArray($owner->$behName->getProcessedRelations(),is_array($data)?$data:array());
		}

		if (isset($this->_alreadySaved[spl_object_hash($owner)]))
			return true;

		/** @var CDbConnection $db */
		$db=$owner->getDbConnection();

		if($db->getCurrentTransaction()===null)
			$transaction=$db->beginTransaction();

		try
		{
			if($data===null)
			{
				$attributes=null;
				$newData=array();
			}
			else
			{
				// not mixing virtual attributes that represents database table columns with real class attributes
				// since real class attributes shouldn't be persisted in the database, it's actual only for validation part
				$attributeNames=$owner->attributeNames();
				// array_intersect must not be used here because when error_reporting is -1 notice will happen
				// since $data array contains not just scalar string values
				$attributes=array_uintersect($data,$attributeNames,
					create_function('$x,$y','return !is_string($x) || !is_string($y) ? -1 : strcmp($x,$y);'));

				if($attributes===array())
					$attributes=null;

				// array_diff must not be used here because when error_reporting is -1 notice will happen
				// since $data array contains not just scalar string values
				$newData=array_udiff($data,$attributeNames,
					create_function('$x,$y','return !is_string($x) || !is_string($y) ? -1 : strcmp($x,$y);'));
			}

			$ownerTableSchema=$owner->getTableSchema();
			/** @var CDbCommandBuilder $builder */
			$builder=$owner->getCommandBuilder();
			$schema=$builder->getSchema();
			$relations=$owner->getMetaData()->relations;
			$queue=array();

			foreach($newData as $name=>$data)
			{
				if(!is_array($data))
				{
					$name=$data;
					$data=null;
				}

				if(!$owner->hasRelated($name))
					continue;

				$relationClass=get_class($relations[$name]);
				$relatedClass=$relations[$name]->className;

				if($relationClass===CActiveRecord::BELONGS_TO)
				{
					/** @var CActiveRecord|CActiveRecord[]|null $related */
					$related=$owner->getRelated($name);
					$relatedTableSchema=CActiveRecord::model($relatedClass)->getTableSchema();
					$keysMap=$this->getDependencyAttributes($relations[$name]->foreignKey, $relatedTableSchema, $ownerTableSchema);

					if (null!==$related) {
						$this->save(false,$data,$related);
					}

					foreach ($keysMap as $fk=>$pk) {
						$owner->$fk=$related ? $related->$pk : null;
					}
				}
				else
					$queue[]=array($relationClass,$relatedClass,$relations[$name]->foreignKey,$name,$data);
			}

			if (!isset($this->_alreadySaved[spl_object_hash($owner)]))
				if(!($owner->getIsNewRecord() ? $owner->insert($attributes) : $owner->update($attributes)))
					return false;

			$this->_alreadySaved[spl_object_hash($owner)] = true;

			foreach($queue as $pack)
			{
				list($relationClass,$relatedClass,$fks,$name,$data)=$pack;
				/** @var CActiveRecord|CActiveRecord[]|null $related */
				$related=$owner->getRelated($name);
				$relatedTableSchema=CActiveRecord::model($relatedClass)->getTableSchema();

				if (null===$related) {
					// TODO: implement unlinking strategies for queued relations
					continue;
				}

				switch($relationClass)
				{
					case CActiveRecord::HAS_ONE:
						$map = $this->getDependencyAttributes($fks, $ownerTableSchema, $relatedTableSchema);
						foreach ($map as $fk => $pk) {
							$related->$fk = $owner->$pk;
						}

						$this->save(false,$data,$related);
						break;
					case CActiveRecord::HAS_MANY:
						$oldOwner = clone $owner;
						$oldRelated = $oldOwner->getRelated($name, true);

						$notCreatedRelated = array_filter($related, function ($model) {
							return !$model->getIsNewRecord();
						});

						$deletedModels = array_udiff($oldRelated, $notCreatedRelated, function ($a, $b) {
							if (is_array($a->primaryKey)) {
								foreach ($a->primaryKey as $k => $v) {
									if ($v > $b->primaryKey[$k]) {
										return 1;
									} elseif ($v < $b->primaryKey[$k]) {
										return -1;
									}
								}

								return 0;
							} else {
								return $a->primaryKey > $b->primaryKey
									? 1
									: ($a->primaryKey < $b->primaryKey ? -1 : 0);
							}
						});

						foreach ($deletedModels as $deletedModel) {
							$deletedModel->delete();
						}

						$map = $this->getDependencyAttributes($fks, $ownerTableSchema, $relatedTableSchema);
						foreach($related as $model)
						{
							foreach($map as $fk=>$pk)
								$model->$fk = $owner->$pk;

							$this->save(false,$data,$model);
						}
						break;
					case CActiveRecord::MANY_MANY:
						if(!preg_match('/^\s*(.*?)\((.*)\)\s*$/',$fks,$matches))
							throw new CDbException(Yii::t('yiiext','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key. The format of the foreign key must be "joinTable(fk1,fk2,...)".',
								array('{class}'=>get_class($owner),'{relation}'=>$name)));

						if(($joinTable=$schema->getTable($matches[1]))===null)
							throw new CDbException(Yii::t('yiiext','The relation "{relation}" in active record class "{class}" is not specified correctly: the join table "{joinTable}" given in the foreign key cannot be found in the database.',
								array('{class}'=>get_class($owner),'{relation}'=>$name,'{joinTable}'=>$matches[1])));

						$fks=preg_split('/\s*,\s*/',$matches[2],-1,PREG_SPLIT_NO_EMPTY);
						$ownerMap=array();
						$relatedMap=array();
						$fkDefined=true;

						foreach($fks as $fk)
						{
							if(!isset($joinTable->columns[$fk]))
								throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key "{key}". There is no such column in the table "{table}".',
									array('{class}'=>get_class($owner),'{relation}'=>$name,'{key}'=>$fk,'{table}'=>$joinTable->name)));

							if(isset($joinTable->foreignKeys[$fk]))
							{
								list($tableName,$pk)=$joinTable->foreignKeys[$fk];

								if(!isset($ownerMap[$pk]) && $schema->compareTableNames($ownerTableSchema->rawName,$tableName))
									$ownerMap[$pk]=$fk;
								else if(!isset($relatedMap[$pk]) && $schema->compareTableNames($relatedTableSchema->rawName,$tableName))
									$relatedMap[$pk]=$fk;
								else
								{
									$fkDefined=false;
									break;
								}
							}
							else
							{
								$fkDefined=false;
								break;
							}
						}

						if(!$fkDefined)
						{
							$ownerMap=array();
							$relatedMap=array();

							foreach($fks as $i=>$fk)
							{
								if($i<count($ownerTableSchema->primaryKey))
								{
									$pk=is_array($ownerTableSchema->primaryKey) ? $ownerTableSchema->primaryKey[$i] : $ownerTableSchema->primaryKey;
									$ownerMap[$pk]=$fk;
								}
								else
								{
									$j=$i-count($ownerTableSchema->primaryKey);
									$pk=is_array($relatedTableSchema->primaryKey) ? $relatedTableSchema->primaryKey[$j] : $relatedTableSchema->primaryKey;
									$relatedMap[$pk]=$fk;
								}
							}
						}

						if($ownerMap===array() && $relatedMap===array())
							throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an incomplete foreign key. The foreign key must consist of columns referencing both joining tables.',
								array('{class}'=>get_class($owner),'{relation}'=>$name)));

						$condition=$builder->createInCondition(
							$joinTable,
							array_values($ownerMap),
							count($ownerTableSchema->primaryKey) > 1 ? array($owner->$pk) : array($ownerTableSchema->primaryKey[0] => $owner->$pk)
						);
						$criteria=$builder->createCriteria($condition);
						$builder->createDeleteCommand($joinTable,$criteria)->execute();

						$insertAttributes=array();

						foreach($related as $model)
						{
							$this->save(false,$data,$model);

							$joinTableAttributes=array();

							foreach((array)@$this->relationAttributes[$name][spl_object_hash($model)] as $attrName=>$attrValue) {
								$joinTableAttributes[$attrName]=$attrValue;
							}

							foreach($ownerMap as $pk=>$fk)
								$joinTableAttributes[$fk]=$owner->$pk;

							foreach($relatedMap as $pk=>$fk)
								$joinTableAttributes[$fk]=$model->$pk;

							$insertAttributes[]=$joinTableAttributes;
						}

						foreach($insertAttributes as $attributes)
							$builder->createInsertCommand($joinTable,$attributes)->execute();
						break;
				}
			}

			if(isset($transaction))
				$transaction->commit();

			return true;
		}
		catch(Exception $e)
		{
			if(isset($transaction))
				$transaction->rollback();

			throw $e;
		}
	}

	/**
	 * @param string $name
	 * @param mixed $keys
	 */
	public function link($name,$keys)
	{
		$owner=$this->getOwner();

		if(!$owner->getMetaData()->hasRelation($name))
			throw new CDbException(Yii::t('yiiext','The relation "{relation}" in active record class "{class}" is not specified.',
				array('{class}'=>get_class($owner),'{relation}'=>$name)));

		$ownerTableSchema=$owner->getTableSchema();
		$builder=$owner->getCommandBuilder();
		$schema=$builder->getSchema();
		$relation=$owner->getMetaData()->relations[$name];
		$relationClass=get_class($relation);
		$relatedClass=$relation->className;

		switch($relationClass)
		{
			case CActiveRecord::BELONGS_TO:
				break;
			case CActiveRecord::HAS_ONE:
				break;
			case CActiveRecord::HAS_MANY:
				break;
			case CActiveRecord::MANY_MANY:
				break;
		}
	}

	/**
	 * @param string $name
	 * @param mixed $keys
	 */
	public function unlink($name,$keys=null)
	{
		$owner=$this->getOwner();

		if(!$owner->getMetaData()->hasRelation($name))
			throw new CDbException(Yii::t('yiiext','The relation "{relation}" in active record class "{class}" is not specified.',
				array('{class}'=>get_class($owner),'{relation}'=>$name)));

		$ownerTableSchema=$owner->getTableSchema();
		$builder=$owner->getCommandBuilder();
		$schema=$builder->getSchema();
		$relation=$owner->getMetaData()->relations[$name];
		$relationClass=get_class($relation);
		$relatedClass=$relation->className;

		switch($relationClass)
		{
			case CActiveRecord::BELONGS_TO:
				break;
			case CActiveRecord::HAS_ONE:
				break;
			case CActiveRecord::HAS_MANY:
				break;
			case CActiveRecord::MANY_MANY:
				break;
		}
	}

	/**
	 * Validate main model and all it's related models recursively and return associative array of errors.
	 * @param array|null $data attributes and relations.
	 * @param CActiveRecord $owner for internal needs.
	 * @return array associative array of errors including errors of relations specified in $data
	 */
	private function internalClearErrors($data,$owner)
	{
		if($owner===null)
			$owner=$this->getOwner();

		if($data===null)
		{
			$attributes=null;
			$newData=array();
		}
		else
		{
			// retrieve real class attributes that was specified in the class declaration
			$classAttributes=get_class_vars(get_class($owner));
			unset($classAttributes['db']); // has nothing in common with the application logic
			$classAttributes=array_keys($classAttributes);

			// mixing virtual attributes that represents database table columns with real class attributes
			$attributeNames=array_merge($classAttributes,$owner->attributeNames());
			// array_intersect must not be used here because when error_reporting is -1 notice will happen
			// since $data array contains not just scalar string values
			$attributes=array_uintersect($data,$attributeNames,
				create_function('$x,$y','return !is_string($x) || !is_string($y) ? -1 : strcmp($x,$y);'));

			if($attributes===array())
				$attributes=null;

			// array_udiff must not be used here because when error_reporting is -1 notice will happen
			// since $data array contains not just scalar string values
			$newData=array_udiff($data,$attributeNames,
				create_function('$x,$y','return !is_string($x) || !is_string($y) ? -1 : strcmp($x,$y);'));
		}

		$owner->clearErrors();

		foreach($newData as $name=>$data)
		{
			if(!is_array($data))
			{
				$name=$data;
				$data=array($name);
			}

			if(!$owner->hasRelated($name))
				continue;

			/** @var CActiveRecord|CActiveRecord[]|null $related */
			$related=$owner->getRelated($name);

			if(null===$related)
				continue;
			elseif(is_array($related))
				foreach ($related as $key => $model)
					$this->internalClearErrors($data,$model);
			else
				$this->internalClearErrors($data,$related);
		}
	}

	/**
	 * Validate main model and all it's related models recursively and return associative array of errors.
	 * @param array|null $data attributes and relations.
	 * @param CActiveRecord $owner for internal needs.
	 * @return array associative array of errors including errors of relations specified in $data
	 * @throws CDbException
	 */
	private function internalValidateAndGetErrors($data,$owner)
	{
		if($owner===null)
			$owner=$this->getOwner();

		if($data===null)
		{
			$attributes=null;
			$newData=array();
		}
		else
		{
			// retrieve real class attributes that was specified in the class declaration
			$classAttributes=get_class_vars(get_class($owner));
			unset($classAttributes['db']); // has nothing in common with the application logic
			$classAttributes=array_keys($classAttributes);

			// mixing virtual attributes that represents database table columns with real class attributes
			$attributeNames=array_merge($classAttributes,$owner->attributeNames());
			// array_intersect must not be used here because when error_reporting is -1 notice will happen
			// since $data array contains not just scalar string values
			$attributes=array_uintersect($data,$attributeNames,
				create_function('$x,$y','return !is_string($x) || !is_string($y) ? -1 : strcmp($x,$y);'));

			if($attributes===array())
				$attributes=null;

			// array_udiff must not be used here because when error_reporting is -1 notice will happen
			// since $data array contains not just scalar string values
			$newData=array_udiff($data,$attributeNames,
				create_function('$x,$y','return !is_string($x) || !is_string($y) ? -1 : strcmp($x,$y);'));

			foreach($newData as $key=>$value)
				if (is_string($value))
				{
					unset($newData[$key]);
					$newData[$value]=array();
				}
		}

		$owner->validate($attributes,false);
		$errors=$owner->errors;
		foreach($newData as $name=>$data)
		{
			if(!is_array($data))
				$name=$data;

			if(!$owner->hasRelated($name))
				continue;

			/** @var CActiveRecord|CActiveRecord[]|null $related */
			$related=$owner->getRelated($name);

			if(null===$related)
				continue;
			elseif(is_array($related))
			{
				foreach ($related as $key => $model)
					if ($relationErrors=$this->getRelationErrors($data,$model))
						$errors[$name][$key]=$relationErrors;
			}
			else
				if($relationErrors=$this->getRelationErrors($data,$related))
					$errors[$name]=$relationErrors;
		}

		return $errors;
	}

	/**
	 * Collects errors of given related object
	 *
	 * @param array|null $data
	 * @param CActiveRecord $model
	 * @return array
	 * @internal param bool $clearErrors
	 */
	private function getRelationErrors($data,$model)
	{
		$relationErrors = array();
		if (is_array($data))
			$relationErrors=$this->internalValidateAndGetErrors($data,$model);
		elseif (!$model->validate(null,false))
			$relationErrors=$model->errors;
		return $relationErrors;
	}

	/**
	 * Get list of processed relations
	 * @return array
	 */
	public function getProcessedRelations()
	{
		return $this->_processedRelations;
	}

	/**
	 * Add relation(s) to processed list
	 * @param array|string $definition
	 * @return $this
	 */
	public function addProcessedRelation($definition)
	{
		if (!is_array($definition)) {
			$definition=array($definition=>array());
		}
		$definition=self::normalizeRelatedArray($definition);
		$this->_processedRelations=CMap::mergeArray($this->_processedRelations,$definition);

		return $this;
	}

	private static function normalizeRelatedArray(array $definition)
	{
		$normalized = array();

		foreach ($definition as $key=>$value)
		{
			if (is_string($value))
			{
				if (!isset($normalized[$value]))
					$normalized[$value]=array();
			}
			elseif (is_array($value))
				$normalized[$key]=self::normalizeRelatedArray($value);
			else
				throw new CException('Values in the relations array must be strings or arrays');
		}

		return $normalized;
	}

	/**
	 * Remove relation from processed list
	 * @param string $relationName
	 * @return $this
	 */
	public function removeProcessedRelation($relationName)
	{
		unset($this->_processedRelations[$relationName]);

		return $this;
	}

	/**
	 * @param $relationName
	 * @param $relatedObject
	 * @throws CException if $relationName is not MANY_MANY relation
	 * @throws CException if $relatedObject is not in the $relationName objects list
	 */
	private function initManyManyAttributes($relationName,$relatedObject)
	{
		$owner=$this->getOwner();
		$objectHash=spl_object_hash($relatedObject);

		if(!in_array($relatedObject,$owner->getRelated($relationName),true))
			throw new CException("The {$relationName} isn't related to Object\{{$objectHash}\}");

		$relation=$owner->getActiveRelation($relationName);
		if (!$relation instanceof CManyManyRelation)
			throw new CException("The {$relationName} isn't MANY_MANY relation");

		if(array_filter((array)$owner->getPrimaryKey()))
		{
			$keyValues=array_merge((array)$owner->primaryKey,(array)$relatedObject->primaryKey);
			$criteria=new CDbCriteria();
			$criteria->addColumnCondition(array_combine($relation->getJunctionForeignKeys(),$keyValues));
			$attributes=$owner->getCommandBuilder()
					->createFindCommand($relation->getJunctionTableName(),$criteria)
					->queryRow();
		} else {
			$columnNames=$owner->getDbConnection()->getSchema()
					->getTable($relation->getJunctionTableName())
					->getColumnNames();
			$attributes=array_fill_keys($columnNames, null);
		}

		$attributes=array_diff_key($attributes,array_flip($relation->getJunctionForeignKeys()));

		$this->relationAttributes[$relationName][$objectHash]=$attributes;
	}

	/**
	 * @param string|array $behDefinition
	 * @param string $thisClass
	 * @return bool
	 * @throws CException
	 */
	private function behaviorClassSubclassOrSelf($behDefinition,$thisClass)
	{
		return (is_string($behDefinition)||is_array($behDefinition))&&$this->subclassOrSelf(
			Yii::import(is_string($behDefinition)?$behDefinition:$behDefinition['class']),
			$thisClass
		);
	}

	/**
	 * @param string $class
	 * @param string $parentOrSelf
	 * @return bool
	 */
	private function subclassOrSelf($class,$parentOrSelf)
	{
		return class_exists($class)&&($class==$parentOrSelf||is_subclass_of($class,$parentOrSelf));
	}
}
