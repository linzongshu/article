<?php
/**
 * Article module MediaEditFilter filter
 *
 * You may not change or alter any portion of this comment or credits
 * of supporting developers from this source code or any supporting source code
 * which is considered copyrighted (c) material of the original comment or credit authors.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * @copyright       Copyright (c) Pi Engine http://www.xoopsengine.org
 * @license         http://www.xoopsengine.org/license New BSD License
 * @author          Zongshu Lin <zongshu@eefocus.com>
 * @since           1.0
 * @package         Module\Article
 */

namespace Module\Article\Form;

use Pi;
use Zend\InputFilter\InputFilter;

/**
 * Class for verifying and filtering form 
 */
class MediaEditFilter extends InputFilter
{
    /**
     * Initializing validator and filter 
     */
    public function __construct($options = array())
    {
        $params = array(
            'table'  => 'media',
        );
        if (isset($options['id']) and $options['id']) {
            $params['id'] = $options['id'];
        }
        $this->add(array(
            'name'     => 'name',
            'required' => true,
            'filters'  => array(
                array(
                    'name' => 'StringTrim',
                ),
            ),
            'validators' => array(
                array(
                    'name'    => 'Module\Article\Validator\RepeatName',
                    'options' => $params,
                ),
            ),
        ));

        $this->add(array(
            'name'     => 'title',
            'required' => true,
            'filters'  => array(
                array(
                    'name' => 'StringTrim',
                ),
            ),
        ));

        $this->add(array(
            'name'     => 'description',
            'required' => false,
        ));

        $this->add(array(
            'name'     => 'url',
            'required' => true,
        ));
        
        $this->add(array(
            'name'     => 'type',
            'required' => false,
        ));

        $this->add(array(
            'name'     => 'id',
            'required' => false,
        ));
    }
}