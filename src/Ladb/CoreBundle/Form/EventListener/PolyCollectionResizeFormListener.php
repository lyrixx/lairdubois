<?php

/*
 * (c) Infinite Networks <http://www.infinite.net.au>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ladb\CoreBundle\Form\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;

/**
 * A Form Resize listener capable of coping with a polycollection.
 *
 * Not a ResizeFormListener any more: Symfony 8 makes its preSetData() and postSetData() final, and
 * its other methods read its private state. It does what ResizeFormListener does with the options
 * of PolyCollectionType (no delete_empty, no keep_as_list), the type of each row aside.
 *
 * @author Tim Nagel <tim@nagel.com.au>
 */
class PolyCollectionResizeFormListener implements EventSubscriberInterface
{
    /**
     * Stores an array of Types with the Type name as the key.
     *
     * @var array
     */
    protected $typeMap = array();

    /**
     * Stores an array of types with the Data Class as the key.
     *
     * @var array
     */
    protected $classMap = array();

    /**
     * Name of the hidden field identifying the type
     *
     * @var string
     */
    protected $typeFieldName;

    /**
     * The type of an object of none of the classes of the prototypes: the first one
     */
    protected $type;
    protected $options;
    protected $allowAdd;
    protected $allowDelete;

    /**
     * @param array<FormInterface> $prototypes
     * @param array $options
     * @param bool $allowAdd
     * @param bool $allowDelete
     * @param string $typeFieldName
     */
    public function __construct(array $prototypes, array $options = array(), $allowAdd = false, $allowDelete = false, $typeFieldName = '_type')
    {
        $this->typeFieldName = $typeFieldName;
        $defaultType = null;

        foreach ($prototypes as $prototype) {
            /** @var FormInterface $prototype */
            $modelClass = $prototype->getConfig()->getOption('model_class');
            $type       = $prototype->getConfig()->getType()->getInnerType();

            if (null === $defaultType) {
                $defaultType = get_class($type);
            }

            // The class of the type: what Form::add() takes
            $typeKey = $type instanceof FormTypeInterface ? $type->getBlockPrefix() : $type;
            $this->typeMap[$typeKey] = get_class($type);
            $this->classMap[$modelClass] = get_class($type);
        }

        $this->type = $defaultType;
        $this->options = $options;
        $this->allowAdd = $allowAdd;
        $this->allowDelete = $allowDelete;
    }

    public static function getSubscribedEvents(): array
    {
        return array(
            // As ResizeFormListener since Symfony 7.2: the rows are built once the data is set, as
            // early as possible
            FormEvents::POST_SET_DATA => array('postSetData', 255),
            FormEvents::PRE_SUBMIT    => 'preSubmit',
            // Before MergeCollectionListener and MergeDoctrineCollectionListener, as in
            // ResizeFormListener
            FormEvents::SUBMIT        => array('onSubmit', 50),
        );
    }

    /**
     * Returns the form type for the supplied object. If a specific
     * form type is not found, it will return the default form type.
     *
     * @param  object $object
     * @return string
     */
    protected function getTypeForObject($object)
    {
        // A lazy entity has its own class (native lazy objects of PHP 8.4, ORM 3), not a proxy one
        $class = get_class($object);

        if (array_key_exists($class, $this->classMap)) {
            return $this->classMap[$class];
        }

        return $this->type;
    }

    /**
     * Checks the form data for a hidden _type field that indicates
     * the form type to use to process the data.
     *
     * @param  array                     $data
     * @return string
     * @throws \InvalidArgumentException when _type is not present or is invalid
     */
    protected function getTypeForData(array $data)
    {
        if (!array_key_exists($this->typeFieldName, $data) || !array_key_exists($data[$this->typeFieldName], $this->typeMap)) {
            throw new \InvalidArgumentException('Unable to determine the Type for given data');
        }

        return $this->typeMap[$data[$this->typeFieldName]];
    }

    public function postSetData(FormEvent $event): void
    {
        $form = $event->getForm();
        $data = $event->getData();

        if (null === $data) {
            $data = array();
        }

        if (!is_array($data) && !($data instanceof \Traversable && $data instanceof \ArrayAccess)) {
            throw new UnexpectedTypeException($data, 'array or (\Traversable and \ArrayAccess)');
        }

        // First remove all rows
        foreach ($form as $name => $child) {
            $form->remove($name);
        }

        // Then add all rows again in the correct order
        foreach ($data as $name => $value) {
            $type = $this->getTypeForObject($value);
            $form->add($name, $type, array_replace(array(
                'property_path' => '['.$name.']',
            ), $this->options));
        }
    }

    public function preBind(FormEvent $event)
    {
        $this->preSubmit($event);
    }

    public function preSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        $data = $event->getData();

        if (null === $data || '' === $data) {
            $data = array();
        }

        if (!is_array($data) && !($data instanceof \Traversable && $data instanceof \ArrayAccess)) {
            throw new UnexpectedTypeException($data, 'array or (\Traversable and \ArrayAccess)');
        }

        // Remove all empty rows
        if ($this->allowDelete) {
            foreach ($form as $name => $child) {
                if (!isset($data[$name])) {
                    $form->remove($name);
                }
            }
        }

        // Add all additional rows
        if ($this->allowAdd) {
            foreach ($data as $name => $value) {
                if (!$form->has($name)) {
                    $type = $this->getTypeForData($value);
                    $form->add($name, $type, array_replace(array(
                        'property_path' => '['.$name.']',
                    ), $this->options));
                }
            }
        }
    }

    public function onSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        $data = $event->getData();

        if (null === $data) {
            $data = array();
        }

        if (!is_array($data) && !($data instanceof \Traversable && $data instanceof \ArrayAccess)) {
            throw new UnexpectedTypeException($data, 'array or (\Traversable and \ArrayAccess)');
        }

        // The data mapper only adds, but does not remove items, so do this here
        if ($this->allowDelete) {
            $toDelete = array();

            foreach ($data as $name => $child) {
                if (!$form->has($name)) {
                    $toDelete[] = $name;
                }
            }

            foreach ($toDelete as $name) {
                unset($data[$name]);
            }
        }

        $event->setData($data);
    }
}
