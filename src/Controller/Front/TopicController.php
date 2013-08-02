<?php
/**
 * Article module topic controller
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

namespace Module\Article\Controller\Front;

use Pi\Mvc\Controller\ActionController;
use Pi;
use Pi\Paginator\Paginator;
use Module\Article\Form\TopicEditForm;
use Module\Article\Form\TopicEditFilter;
use Module\Article\Form\SimpleSearchForm;
use Module\Article\Model\Topic;
use Module\Article\Upload;
use Zend\Db\Sql\Expression;
use Module\Article\Service;
use Module\Article\Cache;
use Module\Article\Model\Article;
use Module\Article\Entity;

/**
 * Public action controller for operating topic
 */
class TopicController extends ActionController
{
    /**
     * Getting topic form object
     * 
     * @param string $action  Form name
     * @return \Module\Article\Form\CategoryEditForm 
     */
    protected function getTopicForm($action = 'add')
    {
        $form = new TopicEditForm();
        $form->setAttributes(array(
            'action'  => $this->url('', array('action' => $action)),
            'method'  => 'post',
            'enctype' => 'multipart/form-data',
            'class'   => 'form-horizontal',
        ));

        return $form;
    }

    /**
     * Saving topic information
     * 
     * @param  array    $data  Topic information
     * @return boolean
     * @throws \Exception 
     */
    protected function saveTopic($data)
    {
        $module     = $this->getModule();
        $modelTopic = $this->getModel('topic');
        $fakeId     = $image = null;

        if (isset($data['id'])) {
            $id = $data['id'];
            unset($data['id']);
        }
        $data['active'] = 1;

        $fakeId = Service::getParam($this, 'fake_id', 0);

        unset($data['image']);

        if (empty($id)) {
            $rowTopic = $modelTopic->createRow($data);
            $rowTopic->save();
            $id       = $rowTopic->id;
        } else {
            $rowTopic = $modelTopic->find($id);

            if (empty($rowTopic->id)) {
                return false;
            }

            $rowTopic->assign($data);
            $rowTopic->save();
        }

        // Save image
        $session    = Upload::getUploadSession($module, 'topic');
        if (isset($session->$id) || ($fakeId && isset($session->$fakeId))) {
            $uploadInfo = isset($session->$id) ? $session->$id : $session->$fakeId;

            if ($uploadInfo) {
                $fileName = $rowTopic->id;

                $pathInfo = pathinfo($uploadInfo['tmp_name']);
                if ($pathInfo['extension']) {
                    $fileName .= '.' . $pathInfo['extension'];
                }
                $fileName = $pathInfo['dirname'] . '/' . $fileName;

                $rowTopic->image = rename(Pi::path($uploadInfo['tmp_name']), Pi::path($fileName)) ? $fileName : $uploadInfo['tmp_name'];
                $rowTopic->save();
            }

            unset($session->$id);
            unset($session->$fakeId);
        }

        return $id;
    }
    
    protected function getCacheKey($category)
    {
        $result = false;

        switch ($category) {
            case '2':
                $result = Cache::KEY_ARTICLE_NEWS_COUNT;
                break;
            case '3':
                $result = Cache::KEY_ARTICLE_PRODUCT_COUNT;
                break;
            case '4':
                $result = Cache::KEY_ARTICLE_DESIGN_COUNT;
                break;
        }

        return $result;
    }

    /**
     * Category index page, which will redirect to category article list page
     */
    public function indexAction()
    {
        return $this->redirect()->toRoute('', array(
            'action'    => 'list',
        ));
    }
    
    /**
     * Processing listing topic articles.
     */
    public function listAction()
    {
        $modelTopic = $this->getModel('topic');

        $topic      = Service::getParam($this, 'topic', '');
        $topicId    = is_numeric($topic) ? (int) $topic : $modelTopic->slugToId($topic);
        $page       = Service::getParam($this, 'p', 1);
        $page       = $page > 0 ? $page : 1;
        $offset     = ($page - 1) * $limit;

        $module = $this->getModule();
        $config = Pi::service('module')->config('', $module);
        $limit  = (int) $config['page_limit_management'] ?: 20;
        $where  = array();
        
        $route  = '.' . Service::getRouteName();

        // Get category info
        $categories = Cache::getCategoryList();
        foreach ($categories as &$row) {
            $row['url'] = $this->url($route, array(
                'category' => $row['slug'] ?: $row['id'],
            ));
        }
        $categoryIds = $modelCategory->getDescendantIds($categoryId);
        if (empty($categoryIds)) {
            return $this->jumpTo404(__('Invalid category id'));
        }
        $where['category']  = $categoryIds;
        $categoryInfo       = $categories[$categoryId];

        // Get articles
        $columns            = array('id', 'subject', 'time_publish', 'category');
        $resultsetArticle   = Entity::getAvailableArticlePage($where, $page, $limit, $columns, null, $module);

        // Total count
        $cacheKey   = $this->getCacheKey($categoryId);
        $totalCount = (int) Cache::getSimple($cacheKey);
        if (empty($totalCount)) {
            $where = array_merge($where, array(
                'time_publish <= ?' => time(),
                'status'            => Article::FIELD_STATUS_PUBLISHED,
                'active'            => 1,
            ));
            $modelArticle   = $this->getModel('article');
            $totalCount     = $modelArticle->getSearchRowsCount($where);

            Cache::setSimple($cacheKey, $totalCount);
        }

        // Pagination
        $paginator = Paginator::factory($totalCount);
        $paginator->setItemCountPerPage($limit)
            ->setCurrentPageNumber($page)
            ->setUrlOptions(array(
                'router'    => $this->getEvent()->getRouter(),
                'route'     => $route,
                'params'    => array(
                    'category'      => $category,
                ),
            ));

        $this->view()->assign(array(
            'title'         => __('Article List in Category'),
            'articles'      => $resultsetArticle,
            'paginator'     => $paginator,
            'categories'    => $categories,
            'categoryInfo'  => $categoryInfo,
            'category'      => $category,
            'p'             => $page,
            'config'        => $config,
            //'seo'           => $this->setupSeo($categoryId),
            'action'        => 'list',
        ));

        $this->view()->viewModel()->getRoot()->setVariables(array(
            'breadCrumbs' => true,
            'Tag'         => $categoryInfo['title'],
        ));
    }
    
    /**
     * Processing listing topic articles.
     */
    public function listArticleAction()
    {
        $modelTopic = $this->getModel('topic');

        $topic      = Service::getParam($this, 'topic', '');
        $page       = Service::getParam($this, 'p', 1);
        $page       = $page > 0 ? $page : 1;
        $offset     = ($page - 1) * $limit;

        $module = $this->getModule();
        $config = Pi::service('module')->config('', $module);
        $limit  = (int) $config['page_limit_management'] ?: 20;
        $where  = array();
        
        if (!empty($topic)) {
            $topicId = is_numeric($topic) ? (int) $topic : $modelTopic->slugToId($topic);
            $where['topic'] = $topicId;
        }
        
        // Selecting articles
        $modelRelation = $this->getModel('article_topic');
        $select        = $modelRelation->select()
                                       ->where($where)
                                       ->offset($offset)
                                       ->limit($limit)
                                       ->order('article DESC');
        $rowArticleSet = $modelRelation->selectWith($select)->toArray();
        
        // Getting article details
        $articleIds    = array();
        foreach ($rowArticleSet as $row) {
            $articleIds[] = $row['article'];
        }
        $articleIds    = empty($articleIds) ? 0 : $articleIds;
        $articles      = Entity::getArticlePage(array('id' => $articleIds), 1, $limit);
        
        // Getting topic details
        $rowTopicSet   = $modelTopic->select(array());
        $topics        = array();
        foreach ($rowTopicSet as $row) {
            $topics[$row['id']] = $row['title'];
        }
        
        // Get category info
        $categories    = Cache::getCategoryList();

        $select        = $modelRelation->select()
                                       ->where($where)
                                       ->columns(array('count' => new Expression('count(id)')));
        $totalCount    = (int) $modelRelation->selectWith($select)->current()->count;

        // Pagination
        $paginator = Paginator::factory($totalCount);
        $paginator->setItemCountPerPage($limit)
            ->setCurrentPageNumber($page)
            ->setUrlOptions(array(
                'router'    => $this->getEvent()->getRouter(),
                'route'     => $this->getEvent()->getRouteMatch()->getMatchedRouteName(),
                'params'    => array_filter(array(
                    'module'        => $module,
                    'controller'    => $this->getEvent()->getRouteMatch()->getParam('controller'),
                    'action'        => $this->getEvent()->getRouteMatch()->getParam('action'),
                    'topic'         => $topic,
                )),
            ));
        
        // Prepare search form
        $form = new SimpleSearchForm;
        $form->setData($this->params()->fromQuery());

        $this->view()->assign(array(
            'title'         => __('Article List in Topic'),
            'articles'      => $rowArticleSet,
            'details'       => $articles,
            'topics'        => $topics,
            'topic'         => $topic,
            'paginator'     => $paginator,
            'p'             => $page,
            'config'        => $config,
            'action'        => 'list-article',
            'form'          => $form,
        ));
    }
    
    /**
     * Listing all articles for pull. 
     */
    public function pullAction()
    {
        // Checking permission
        /*$allowed = Service::getPermission('topic');
        if (!$allowed) {
            return $this->jumpToDenied('__Denied__');
        }*/
        
        $where  = array();
        $page   = Service::getParam($this, 'page', 1);
        $limit  = Service::getParam($this, 'limit', 20);
        $order  = 'time_publish DESC';

        $data   = $ids = array();

        $module         = $this->getModule();
        $modelArticle   = $this->getModel('article');
        $categoryModel  = $this->getModel('category');

        // Getting category
        $category = Service::getParam($this, 'category', 0);
        if ($category > 1) {
            $categoryIds = $categoryModel->getDescendantIds($category);
            if ($categoryIds) {
                $where['category'] = $categoryIds;
            }
        }
        
        // Getting topic
        $modelTopic = $this->getModel('topic');
        $topics     = $modelTopic->getList();

        // Build where
        $where['status'] = Article::FIELD_STATUS_PUBLISHED;
        
        $keyword = Service::getParam($this, 'keyword', '');
        if (!empty($keyword)) {
            $where['subject like ?'] = sprintf('%%%s%%', $keyword);
        }

        // Retrieve data
        $data = Entity::getArticlePage($where, $page, $limit, null, $order, $module);
        
        // Getting article topic
        $articleIds  = array_keys($data);
        $rowRelation = $this->getModel('article_topic')->select(array('article' => $articleIds));
        $relation    = array();
        foreach ($rowRelation as $row) {
            if (isset($relation[$row['article']])) {
                $relation[$row['article']] .= ',' . $topics[$row['topic']];
            } else {
                $relation[$row['article']] .= $topics[$row['topic']];
            }
        }

        // Total count
        $select = $modelArticle->select()
            ->columns(array('total' => new Expression('count(id)')))
            ->where($where);
        $resulsetCount = $modelArticle->selectWith($select);
        $totalCount    = (int) $resulsetCount->current()->total;

        // Paginator
        $paginator = Paginator::factory($totalCount);
        $paginator->setItemCountPerPage($limit)
            ->setCurrentPageNumber($page)
            ->setUrlOptions(array(
            'router'    => $this->getEvent()->getRouter(),
            'route'     => $this->getEvent()->getRouteMatch()->getMatchedRouteName(),
            'params'    => array_filter(array(
                'module'        => $module,
                'controller'    => $this->getEvent()->getRouteMatch()->getParam('controller'),
                'action'        => $this->getEvent()->getRouteMatch()->getParam('action'),
                'category'      => $category,
            )),
        ));

        // Prepare search form
        $form = new SimpleSearchForm;
        $form->setData($this->params()->fromQuery());
        
        $this->view()->assign(array(
            'title'      => __('All Articles'),
            'data'       => $data,
            'form'       => $form,
            'paginator'  => $paginator,
            'category'   => $category,
            'categories' => Cache::getCategoryList(),
            'action'     => 'pull',
            'topics'     => $topics,
            'relation'   => $relation,
        ));
    }
    
    public function pullArticleAction()
    {
        // Checking permission
        /*$allowed = Service::getPermission('topic');
        if (!$allowed) {
            return $this->jumpToDenied('__Denied__');
        }*/
        
        $topic = Service::getParam($this, 'topic', '');
        $id    = Service::getParam($this, 'id', 0);
        $ids   = array_filter(explode(',', $id));
        $from  = Service::getParam($this, 'from', '');
        if (empty($topic) or empty($ids)) {
            return $this->jumpTo404('Invalid ID or topic');
        }
        
        $data  = array();
        foreach ($ids as $value) {
            $data[$value] = array(
                'article' => $value,
                'topic'   => $topic,
            );
        }
        
        $model = $this->getModel('article_topic');
        $rows  = $model->select(array('article' => $ids));
        foreach ($rows as $row) {
            if ($topic == $row->topic) {
                unset($data[$row->article]);
            }
        }
        
        foreach ($data as $item) {
            $row = $model->createRow($item);
            $row->save();
        }
        
        if ($from) {
            $from = urldecode($from);
            return $this->redirect()->toUrl($from);
        } else {
            return $this->redirect()->toRoute('', array('action' => 'list-article'));
        }
    }
    
    public function removePullAction()
    {
        /*$allowed = Service::getPermission('topic');
        if (!$allowed) {
            return $this->jumpToDenied('__Denied__');
        }*/
        
        $id    = Service::getParam($this, 'id', 0);
        $ids   = array_filter(explode(',', $id));
        $from  = Service::getParam($this, 'from', '');
        if (empty($ids)) {
            return $this->jumpTo404('Invalid ID!');
        }
        
        $this->getModel('article_topic')->delete(array('id' => $ids));
                
        if ($from) {
            $from = urldecode($from);
            return $this->redirect()->toUrl($from);
        } else {
            return $this->redirect()->toRoute('', array('action' => 'list-article'));
        }
    }
    
    /**
     * Adding category information
     * 
     * @return ViewModel 
     */
    public function addAction()
    {
        /*$allowed = Service::getPermission('topic');
        if (!$allowed) {
            return $this->jumpToDenied('__Denied__');
        }*/
        
        $form   = $this->getTopicForm('add');
        $form->setData(array(
            'fake_id'  => Upload::randomKey(),
        ));

        Service::setModuleConfig($this);
        $this->view()->assign(array(
            'title'                 => __('Add Topic Info'),
            'form'                  => $form,
        ));
        $this->view()->setTemplate('topic-edit');
        
        if ($this->request->isPost()) {
            $post = $this->request->getPost();
            $form->setData($post);
            $form->setInputFilter(new TopicEditFilter);
            $form->setValidationGroup(Topic::getAvailableFields());
            if (!$form->isValid()) {d($form->getMessages());
                return Service::renderForm($this, $form, __('There are some error occured!'), true);
            }
            
            $data = $form->getData();
            $id   = $this->saveTopic($data);
            if (!$id) {
                return Service::renderForm($this, $form, __('Can not save data!'), true);
            }
            return $this->redirect()->toRoute('', array('action' => 'list-topic'));
        }
    }

    /**
     * Editing topic information
     * 
     * @return ViewModel
     */
    public function editAction()
    {
        /*$allowed = Service::getPermission('topic');
        if (!$allowed) {
            return $this->jumpToDenied('__Denied__');
        }*/
        
        Service::setModuleConfig($this);
        $this->view()->assign('title', __('Edit Topic Info'));
        
        $form = $this->getTopicForm('edit');
        
        if ($this->request->isPost()) {
            $post = $this->request->getPost();
            $form->setData($post);
            $form->setInputFilter(new TopicEditFilter);
            $form->setValidationGroup(Topic::getAvailableFields());
            if (!$form->isValid()) {
                return Service::renderForm($this, $form, __('Can not update data!'), true);
            }
            $data = $form->getData();
            $id   = $this->saveTopic($data);

            return $this->redirect()->toRoute('', array('action' => 'list-topic'));
        }
        
        $id     = $this->params('id', 0);
        if (empty($id)) {
            $this->jumpto404(__('Invalid topic id!'));
        }

        $model = $this->getModel('topic');
        $row   = $model->find($id);
        if (!$row->id) {
            return $this->jumpTo404(__('Can not find topic!'));
        }
        
        $form->setData($row->toArray());

        $this->view()->assign('form', $form);
    }
    
    /**
     * Deleting a topic
     * 
     * @throws \Exception 
     */
    public function deleteAction()
    {
        /*$allowed = Service::getPermission('topic');
        if (!$allowed) {
            return $this->jumpToDenied('__Denied__');
        }*/
        
        $id     = $this->params('id');
        if (empty($id)) {
            throw new \Exception(__('Invalid topic id'));
        }

        $topicModel = $this->getModel('topic');

        // Remove relationship between topic and articles
        $this->getModel('article_topic')->delete(array('topic' => $id));

        // Delete image
        $row = $topicModel->find($id);
        if ($row && $row->image) {
            unlink(Pi::path($row->image));
        }

        // Remove topic
        $topicModel->delete(array('id' => $id));

        // Go to list page
        return $this->redirect()->toRoute('', array('action' => 'list-topic'));
    }

    /**
     * Processing added topic list
     */
    public function listTopicAction()
    {
        /*$allowed = Service::getPermission('category');
        if (!$allowed) {
            return $this->jumpToDenied('__Denied__');
        }*/
        
        $module = $this->getModule();
        $config = Pi::service('module')->config('', $module);
        $limit  = (int) $config['page_limit_management'] ?: 20;
        $page   = Service::getParam($this, 'p', 1);
        $page   = $page > 0 ? $page : 1;
        $offset = ($page - 1) * $limit;
        
        $model  = $this->getModel('topic');
        $select = $model->select()
                        ->offset($offset)
                        ->limit($limit);
        $rowset = $model->selectWith($select);
        
        $select = $model->select()->columns(array('count' => new Expression('count(*)')));
        $count  = (int) $model->selectWith($select)->current()->count;
        
        $paginator = Paginator::factory($count);
        $paginator->setItemCountPerPage($limit);
        $paginator->setCurrentPageNumber($page);
        $paginator->setUrlOptions(array(
            'router'        => $this->getEvent()->getRouter(),
            'route'         => $this->getEvent()->getRouteMatch()->getMatchedRouteName(),
            'params'        => array(
                'module'        => $this->getModule(),
                'controller'    => 'topic',
                'action'        => 'list-topic',
            ),
        ));

        $this->view()->assign('topics', $rowset);
        $this->view()->assign('title', __('Topic List'));
        $this->view()->assign('action', 'list-topic');
    }

    /**
     * Active or deactivate a topic.
     * 
     * @return ViewModel 
     */
    public function activeAction()
    {
        /*$allowed = Service::getPermission('category');
        if (!$allowed) {
            return $this->jumpToDenied('__Denied__');
        }*/
        
        $status = Service::getParam($this, 'status', 0);
        $id     = Service::getParam($this, 'id', 0);
        $from   = Service::getParam($this, 'from', 0);
        if (empty($id)) {
            return $this->jumpTo404(__('Invalid ID!'));
        }
        
        $this->getModel('topic')->setActiveStatus($id, $status);
        
        if ($from) {
            $from = urldecode($from);
            return $this->redirect()->toUrl($from);
        } else {
            return $this->redirect()->toRoute('', array('action' => 'list-topic'));
        }
    }
}