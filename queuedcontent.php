<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.QueuedContent
 *
 * @copyright   Copyright (C) NPE 2019.
 * @license     MIT License; see LICENSE.md
 */

defined('_JEXEC') or die;

/**
 * Queues content for future publishing.
 */
class plgSystemQueuedContent extends JPlugin
{
    protected $autoloadLanguage = true;

    /**
     * Updates an article and saves it back to the database.
     *
     * @param  object   &$item    The article object
     *
     * @return void
     */
    protected function updateArticle(&$item)
    {
        $text = explode('<hr id="system-readmore" />', $item->queuedcontent['queued_content']);
        $introtext = $text[0];
        $fulltext = '';
        if (isset($text[1])) {
            $fulltext = $text[1];
        }

        $item->introtext = $introtext;
        $item->fulltext  = $fulltext;
        $item->text      = implode(' ', $text);
        $item->text      = implode(' ', $text);

        $this->item      = $item;

        $data = (array) $item;
        $data['articletext'] = $item->text;

        // Can't load Admin version of ContentModelArticle because Site version is needed to load
        // content properly, but this means there's no 'save' method available for updating the
        // article. I can't find a way round this, so we're actually re-loading it from the database
        // first so we can use all the JTable functionality, including automatic versions.
        $article = JTable::getInstance('content');

        $article->load($item->id);
        $article->introtext = $item->introtext;
        $article->fulltext = $item->fulltext;

        JEventDispatcher::getInstance()->trigger('onContentBeforeSave', array('com_content.article', $article, false, $data));

        $article->store();

        #JEventDispatcher::getInstance()->trigger('onContentAfterSave', array('com_content.article', $article, false $data));

        $this->clearQueue($item->id);
    }

    /**
     * Retrieves queue entry for an item,
     *
     * @param   integer  $item_id  The id of the item we're getting
     *
     * @return  null|object
     */
    protected function getQueue($item_id)
    {
        $app = JFactory::getApplication();
        $db  = JFactory::getDbo();

        $query = $db->getQuery(true);
        $query->select(array($query->qn('publish_date'), $query->qn('queued_content')))
              ->from($query->qn('#__content_queue'))
              ->where($query->qn('content_id') . ' = ' . $query->q($item_id));

        return $db->setQuery($query)->loadObject();
    }


    /**
     * Deletes queue entrie for an item.
     *
     * @param   integer  $item_id  The id of the item we're clearing
     *
     * @return  void
     */
    protected function clearQueue($item_id)
    {
        $app = JFactory::getApplication();
        $db  = JFactory::getDbo();

        $query = $db->getQuery(true);
        $query->delete($query->qn('#__content_queue'))
              ->where($query->qn('content_id') . ' = ' . $query->q($item_id));
        $db->setQuery($query)->execute();
    }

    /**
     * Checks for future content and if found and publish time has passed, update the content and
     * save it back to the database.
     *
     * @param   string   $context  The context of the content being passed to the plugin
     * @param   object   &$item    The article object
     * @param   object   &$params  The article params
     * @param   integer  $page     The 'page' number
     *
     * @return  void|false
     */
    public function onContentPrepare($context, &$item, &$params, $page = 0)
    {
        // Check we're running in the right context:
        if (strpos($context, 'com_content') !== 0) {
            return true;
        }

        // And that the item we're dealing with is an article:
        // (this may not be the most robust check, but currently nothing else has 'introtext')
        if (!isset($item->introtext)) {
            return true;
        }

        // Get queued item if available:
        if ($queue = $this->getQueue($item->id)) {
            $item->queuedcontent = array(
                'publish_date'   => $queue->publish_date,
                'queued_content' => $queue->queued_content
            );
        } else {
            return true;
        }

        if ($item->queuedcontent['publish_date'] == '0000-00-00 00:00:00' || strtotime(new JDate('now', 'Europe/London')) < strtotime(new JDate($item->queuedcontent['publish_date']))) {
            // No date or future date not yet arrived. Do nothing.
            return true;
        }

        $this->updateArticle($item);

        return true;
    }


    /**
     * Checks we're loading an article form and adds necessary resources.
     *
     * @param   string   $context  The context of the content being passed to the plugin
     * @param   object   $data     The data object
     *
     * @return  boolean
     */
    public function onContentPrepareData($context, $data)
    {
        // Check we're running in the right context:
        if (strpos($context, 'com_content') !== 0) {
            return true;
        }

        // And that the item we're dealing with is an article:
        // (this may not be the most robust check, but currently nothing else has 'introtext')
        if (!isset($data->introtext)) {
            return true;
        }

        // Get queued item if available:
        if ($queue = $this->getQueue($data->id)) {
            $data->queuedcontent = array(
                'publish_date'   => $queue->publish_date,
                'queued_content' => $queue->queued_content
            );
        }

        $document = JFactory::getDocument();
        $document->addScript('/plugins/system/queuedcontent/js/queuedcontent.js');

        return true;
    }

    /**
     * Prepare form.
     *
     * @param   JForm  $form  The form to be altered.
     * @param   mixed  $data  The associated data for the form.
     *
     * @return  boolean
     */
    public function onContentPrepareForm(JForm $form, $data)
    {
        if (!($form instanceof JForm)) {
            $this->_subject->setError('JERROR_NOT_A_FORM');
            return true;
        }

        if ($form->getName() != 'com_content.article') {
            return true; // We only want to manipulate the article form.
        }

        // Add the extra fields to the form
        JForm::addFormPath(__DIR__ . '/forms');
        $form->loadFile('queuedcontent', false);
        return true;
    }

    /**
     * The save event.
     *
     * @param   string   $context  The context
     * @param   JTable   $item     The article data
     * @param   boolean  $isNew    Is new item
     * @param   array    $data     The validated data
     *
     * @return  boolean
     *
     */
    public function onContentAfterSave($context, $item, $isNew, $data = array())
    {

        $app = JFactory::getApplication();
        $db  = JFactory::getDbo();

        if (!$app->isClient('administrator')) {
            return true; // Only run in admin
        }

        if ($context != 'com_content.article') {
            return true; // Only run for articles
        }

        // Delete any existing records (we can only make use of one anyway):
        $this->clearQueue($data['id']);

        if (!empty($data['queuedcontent']['publish_date'])) {
            $date = new JDate($data['queuedcontent']['publish_date']);
            $date = $date->toSql();
        } else {
            $date = '';
        }

        $content = false;

        if (!empty($data['queuedcontent']['queued_content'])) {
            $content = trim($data['queuedcontent']['queued_content']);
        }

        if (empty($content)) {
            return true;
        }

        $queue = new stdClass;
        $queue->content_id     = (int) $data['id'];
        $queue->publish_date   = $date;
        $queue->queued_content = $content;

        $db->insertObject('#__content_queue', $queue);

        JFactory::getApplication()->enqueueMessage(JText::_('PLG_SYSTEM_QUEUED_CONTENT_SAVE_SUCCESS'), 'message');

        return true;
    }


    /**
     * Hack for adding future-publish event listener to Joomla calendar custom field.
     * Note I haven't found a way to do this in JS and the 'correct' way to specify event handlers
     * is actually in the markup anyway, so more of a fudge and a hack.
     *
     * @return void
     */
    public function onAfterRender()
    {
        $response     = JResponse::getBody();
        $search       = 'id="jform_queuedcontent_publish_date"';
        $replace      = 'onchange="QueuedContent.joomlaFieldCalendarUpdateAction()" id="jform_queuedcontent_publish_date"';
        $response     = str_replace($search, $replace, $response);
        JResponse::setBody($response);
    }
}