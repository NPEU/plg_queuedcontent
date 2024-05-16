<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.QueuedContent
 *
 * @copyright   Copyright (C) NPEU 2024.
 * @license     MIT License; see LICENSE.md
 */

namespace NPEU\Plugin\System\QueuedContent\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * Queues content for future publishing.
 */
class QueuedContent extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    /**
     * An internal flag whether plugin should listen any event.
     *
     * @var bool
     *
     * @since   4.3.0
     */
    protected static $enabled = false;

    /**
     * Constructor
     *
     */
    public function __construct($subject, array $config = [], bool $enabled = true)
    {
        // The above enabled parameter was taken from the Guided Tour plugin but it always seems
        // to be false so I'm not sure where this param is passed from. Overriding it for now.
        $enabled = true;


        #$this->loadLanguage();
        $this->autoloadLanguage = $enabled;
        self::$enabled          = $enabled;

        parent::__construct($subject, $config);
    }

    /**
     * function for getSubscribedEvents : new Joomla 4 feature
     *
     * @return array
     *
     * @since   4.3.0
     */
    public static function getSubscribedEvents(): array
    {
        return self::$enabled ? [
            'onContentPrepare'     => 'onContentPrepare',
            'onContentPrepareData' => 'onContentPrepareData',
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onContentAfterSave'   => 'onContentAfterSave',
            'onAfterRender'        => 'onAfterRender'
        ] : [];
    }

    /**
     * Prepare content.
     *
     * @param   Event  $event
     *
     * @return  boolean
     */
    public function onContentPrepare(Event $event): void
    {
        [$context, $item, $params, $page] = array_values($event->getArguments());

        // Check we're running in the right context:
        if (strpos($context, 'com_content') !== 0) {
            return;
        }

        // And that the item we're dealing with is an article:
        // (this may not be the most robust check, but currently nothing else has 'introtext')
        if (!isset($item->introtext)) {
            return;
        }

        // Get queued item if available:
        if ($queue = $this->getQueue($item->id)) {
            $item->queuedcontent = array(
                'publish_date'   => $queue->publish_date,
                'queued_content' => $queue->queued_content
            );
        } else {
            return;
        }

        if ($item->queuedcontent['publish_date'] == '0000-00-00 00:00:00' || strtotime(new Date('now', 'Europe/London')) < strtotime(new Date($item->queuedcontent['publish_date']))) {
            // No date or future date not yet arrived. Do nothing.
            return;
        }

        if($this->updateArticle($item)) {
            $this->clearQueue($item->id);
        }

        return;
    }

    public function onContentPrepareData(Event $event): void
    {
        [$context, $data] = array_values($event->getArguments());

        // Check we're running in the right context:
        if (strpos($context, 'com_content') !== 0) {
            return;
        }

        // And that the item we're dealing with is an article:
        // (this may not be the most robust check, but currently nothing else has 'introtext')
        if (!isset($data->introtext)) {
            return;
        }

        // Get queued item if available:
        if ($queue = $this->getQueue($data->id)) {
            $data->queuedcontent = array(
                'publish_date'   => $queue->publish_date,
                'queued_content' => $queue->queued_content
            );
        }

        $document = Factory::getDocument();
        $document->addScript('/media/vendor/jquery/js/jquery.min.js');
        #$document->addScript('/media/legacy/jquery-noconflict.min.js');
        $document->addScript('/plugins/system/queuedcontent/js/queuedcontent.js');
        return;
    }

    /**
     * Prepare form.
     *
     * @param   Event  $event
     *
     * @return  boolean
     */
    public function onContentPrepareForm(Event $event): void
    {
        [$form, $data] = array_values($event->getArguments());

        if (!($form instanceof \Joomla\CMS\Form\Form)) {
            $this->_subject->setError('JERROR_NOT_A_FORM');
            return;
        }

        if ($form->getName() != 'com_content.article') {
            return; // We only want to manipulate the article form.
        }

        // Add the extra fields to the form
        FormHelper::addFormPath(dirname(dirname(__DIR__)) . '/forms');
        $form->loadFile('queuedcontent', false);
        return;
    }

    /**
     *
     * @param   Event  $event
     *
     * @return  void
     */
    public function onContentAfterSave(Event $event): void
    {
        [$context, $object, $isNew, $data] = array_values($event->getArguments());

        // Check if we're saving an article:
        if ($context != 'com_content.article') {
            return; // Only run for articles
        }

        $app = Factory::getApplication();
        $db  = Factory::getDbo();

        if (!$app->isClient('administrator')) {
            return; // Only run in admin
        }

        // Delete any existing records (we can only make use of one anyway):
        $this->clearQueue($data['id']);

        if (!empty($data['queuedcontent']['publish_date'])) {
            $date = new Date($data['queuedcontent']['publish_date']);
            $date = $date->toSql();
        } else {
            $date = '';
        }

        $content = false;

        if (!empty($data['queuedcontent']['queued_content'])) {
            $content = trim($data['queuedcontent']['queued_content']);
        }

        if (empty($content)) {
            return;
        }

        $queue = new \stdClass;
        $queue->content_id     = (int) $data['id'];
        $queue->publish_date   = $date;
        $queue->queued_content = $content;

        $db->insertObject('#__content_queue', $queue);

        $app->enqueueMessage(Text::_('PLG_SYSTEM_QUEUED_CONTENT_SAVE_SUCCESS'), 'message');

        return;
    }


    /**
     * Replace strings in the body.
     */
    public function onAfterRender(Event $event): void
    {
        $app = Factory::getApplication();

        $response     = $app->getBody();
        $search       = 'id="jform_queuedcontent_publish_date"';
        $replace      = 'onchange="QueuedContent.joomlaFieldCalendarUpdateAction()" id="jform_queuedcontent_publish_date"';
        $response     = str_replace($search, $replace, $response);
        $app->setBody($response);
    }

    /**
     * Updates an article and saves it back to the database.
     *
     * @param  object   $item    The article object
     *
     * @return void
     */
    protected function updateArticle($item)
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

        // Save the article (J4 style - not sure if this works yet)

        $app = Factory::getApplication();

        $article_model = $app->bootComponent('com_content')->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);

        // or  $config= array(); $article_model =  new ContentModelArticle($config);
        if (!$article_model->save($data)) {
            $err_msg = $article_model->getError();
            return false;
        } else {
            return true;
        }

        // Can't load Admin version of ContentModelArticle because Site version is needed to load
        // content properly, but this means there's no 'save' method available for updating the
        // article. I can't find a way round this, so we're actually re-loading it from the database
        // first so we can use all the JTable functionality, including automatic versions.
        /*$article = JTable::getInstance('content');

        $article->load($item->id);
        $article->introtext = $item->introtext;
        $article->fulltext = $item->fulltext;

        JEventDispatcher::getInstance()->trigger('onContentBeforeSave', array('com_content.article', $article, false, $data));

        $article->store();
        */

        #JEventDispatcher::getInstance()->trigger('onContentAfterSave', array('com_content.article', $article, false $data));


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
        $app = Factory::getApplication();
        $db  = Factory::getDbo();

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
        $app = Factory::getApplication();
        $db  = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->delete($query->qn('#__content_queue'))
              ->where($query->qn('content_id') . ' = ' . $query->q($item_id));
        $db->setQuery($query)->execute();
    }
}