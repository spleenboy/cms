<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\services;

use Craft;
use craft\base\Field;
use craft\db\Query;
use craft\elements\Entry;
use craft\errors\EntryDraftNotFoundException;
use craft\events\DraftEvent;
use craft\events\VersionEvent;
use craft\helpers\Json;
use craft\models\EntryDraft;
use craft\models\EntryVersion;
use craft\models\Section;
use craft\records\EntryDraft as EntryDraftRecord;
use craft\records\EntryVersion as EntryVersionRecord;
use yii\base\Component;

/**
 * Class EntryRevisions service.
 *
 * An instance of the EntryRevisions service is globally accessible in Craft via [[Application::entryRevisions `Craft::$app->getEntryRevisions()`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class EntryRevisions extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event DraftEvent The event that is triggered before a draft is saved.
     */
    const EVENT_BEFORE_SAVE_DRAFT = 'beforeSaveDraft';

    /**
     * @event DraftEvent The event that is triggered after a draft is saved.
     */
    const EVENT_AFTER_SAVE_DRAFT = 'afterSaveDraft';

    /**
     * @event DraftEvent The event that is triggered before a draft is published.
     */
    const EVENT_BEFORE_PUBLISH_DRAFT = 'beforePublishDraft';

    /**
     * @event DraftEvent The event that is triggered after a draft is published.
     */
    const EVENT_AFTER_PUBLISH_DRAFT = 'afterPublishDraft';

    /**
     * @event DraftEvent The event that is triggered before a draft is deleted.
     */
    const EVENT_BEFORE_DELETE_DRAFT = 'beforeDeleteDraft';

    /**
     * @event DraftEvent The event that is triggered after a draft is deleted.
     */
    const EVENT_AFTER_DELETE_DRAFT = 'afterDeleteDraft';

    /**
     * @event VersionEvent The event that is triggered before an entry is reverted to an old version.
     */
    const EVENT_BEFORE_REVERT_ENTRY_TO_VERSION = 'beforeRevertEntryToVersion';

    /**
     * @event VersionEvent The event that is triggered after an entry is reverted to an old version.
     */
    const EVENT_AFTER_REVERT_ENTRY_TO_VERSION = 'afterRevertEntryToVersion';

    // Public Methods
    // =========================================================================

    /**
     * Returns a draft by its ID.
     *
     * @param int $draftId
     *
     * @return EntryDraft|null
     */
    public function getDraftById(int $draftId)
    {
        $draftRecord = EntryDraftRecord::findOne($draftId);

        if ($draftRecord) {
            $config = $draftRecord->toArray([
                'id',
                'entryId',
                'sectionId',
                'creatorId',
                'siteId',
                'name',
                'notes',
                'data',
                'dateCreated',
                'dateUpdated',
                'uid',
            ]);
            $config['data'] = Json::decode($config['data']);
            $draft = new EntryDraft($config);

            // This is a little hacky, but fixes a bug where entries are getting the wrong URL when a draft is published
            // inside of a structured section since the selected URL Format depends on the entry's level, and there's no
            // reason to store the level along with the other draft data.
            $entry = Craft::$app->getEntries()->getEntryById($draftRecord->entryId, $draftRecord->siteId);

            $draft->root = $entry->root;
            $draft->lft = $entry->lft;
            $draft->rgt = $entry->rgt;
            $draft->level = $entry->level;

            return $draft;
        }

        return null;
    }

    /**
     * Returns drafts of a given entry.
     *
     * @param int      $entryId
     * @param int|null $siteId
     *
     * @return EntryDraft[]
     */
    public function getDraftsByEntryId(int $entryId, int $siteId = null): array
    {
        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        }

        $drafts = [];

        $results = (new Query())
            ->select([
                'id',
                'entryId',
                'sectionId',
                'creatorId',
                'siteId',
                'name',
                'notes',
                'data',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from(['{{%entrydrafts}}'])
            ->where(['entryId' => $entryId, 'siteId' => $siteId])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        foreach ($results as $result) {
            $result['data'] = Json::decode($result['data']);

            // Don't initialize the content
            unset($result['data']['fields']);

            $drafts[] = new EntryDraft($result);
        }

        return $drafts;
    }

    /**
     * Returns the drafts of a given entry that are editable by the current user.
     *
     * @param int      $entryId
     * @param int|null $siteId
     *
     * @return EntryDraft[]
     */
    public function getEditableDraftsByEntryId(int $entryId, int $siteId = null): array
    {
        $editableDrafts = [];
        $user = Craft::$app->getUser()->getIdentity();

        if ($user) {
            $allDrafts = $this->getDraftsByEntryId($entryId, $siteId);

            foreach ($allDrafts as $draft) {
                if ($draft->creatorId == $user->id || $user->can('editPeerEntryDrafts:'.$draft->sectionId)) {
                    $editableDrafts[] = $draft;
                }
            }
        }

        return $editableDrafts;
    }

    /**
     * Saves a draft.
     *
     * @param EntryDraft $draft         The draft to be saved
     * @param bool       $runValidation Whether to perform validation
     *
     * @return bool
     */
    public function saveDraft(EntryDraft $draft, bool $runValidation = true): bool
    {
        if ($runValidation && !$draft->validate()) {
            Craft::info('Draft not saved due to validation error.', __METHOD__);

            return false;
        }

        $isNewDraft = !$draft->draftId;

        if (!$draft->name && $draft->id) {
            // Get the total number of existing drafts for this entry/site
            $totalDrafts = (new Query())
                ->from(['{{%entrydrafts}}'])
                ->where(['entryId' => $draft->id, 'siteId' => $draft->siteId])
                ->count('[[id]]');

            $draft->name = Craft::t('app', 'Draft {num}',
                ['num' => $totalDrafts + 1]);
        }

        // Fire a 'beforeSaveDraft' event
        $this->trigger(self::EVENT_BEFORE_SAVE_DRAFT, new DraftEvent([
            'draft' => $draft,
            'isNew' => $isNewDraft,
        ]));

        $draftRecord = $this->_getDraftRecord($draft);
        $draftRecord->name = $draft->name;
        $draftRecord->notes = $draft->revisionNotes;
        $draftRecord->data = $this->_getRevisionData($draft);

        $draftRecord->save(false);

        if ($isNewDraft) {
            $draft->draftId = $draftRecord->id;
        }

        // Fire an 'afterSaveDraft' event
        $this->trigger(self::EVENT_AFTER_SAVE_DRAFT, new DraftEvent([
            'draft' => $draft,
            'isNew' => $isNewDraft,
        ]));

        return true;
    }

    /**
     * Publishes a draft.
     *
     * @param EntryDraft $draft         The draft to be published
     * @param bool       $runValidation Whether to perform validation
     *
     * @return bool
     */
    public function publishDraft(EntryDraft $draft, bool $runValidation = true): bool
    {
        // If this is a single, we'll have to set the title manually
        if ($draft->getSection()->type == Section::TYPE_SINGLE) {
            $draft->title = $draft->getSection()->name;
        }

        if ($runValidation && !$draft->validate()) {
            Craft::info('Draft not published due to validation error.', __METHOD__);

            return false;
        }

        // Set the version notes
        if (!$draft->revisionNotes) {
            $draft->revisionNotes = Craft::t('app', 'Published draft “{name}”.',
                ['name' => $draft->name]);
        }

        // Fire a 'beforePublishDraft' event
        $this->trigger(self::EVENT_BEFORE_PUBLISH_DRAFT, new DraftEvent([
            'draft' => $draft
        ]));

        // Save the entry without re-running validation on it
        Craft::$app->getElements()->saveElement($draft, false);

        // Delete the draft
        $this->deleteDraft($draft);

        // Fire an 'afterPublishDraft' event
        $this->trigger(self::EVENT_AFTER_PUBLISH_DRAFT, new DraftEvent([
            'draft' => $draft
        ]));

        return true;
    }

    /**
     * Deletes a draft by it's model.
     *
     * @param EntryDraft $draft The draft to be deleted
     *
     * @return bool Whether the draft was deleted successfully
     */
    public function deleteDraft(EntryDraft $draft): bool
    {
        // Fire a 'beforeDeleteDraft' event
        $this->trigger(self::EVENT_BEFORE_DELETE_DRAFT, new DraftEvent([
            'draft' => $draft
        ]));

        // Delete it
        $this->_getDraftRecord($draft)->delete();

        // Fire an 'afterDeleteDraft' event
        $this->trigger(self::EVENT_AFTER_DELETE_DRAFT, new DraftEvent([
            'draft' => $draft
        ]));

        return true;
    }

    /**
     * Returns a version by its ID.
     *
     * @param int $versionId
     *
     * @return EntryVersion|null
     */
    public function getVersionById(int $versionId)
    {
        $versionRecord = EntryVersionRecord::findOne($versionId);

        if ($versionRecord) {
            $config = $versionRecord->toArray([
                'id',
                'entryId',
                'sectionId',
                'creatorId',
                'siteId',
                'num',
                'notes',
                'data',
                'dateCreated',
                'dateUpdated',
                'uid',
            ]);
            $config['data'] = Json::decode($config['data']);

            return new EntryVersion($config);
        }

        return null;
    }

    /**
     * Returns versions by an entry ID.
     *
     * @param int      $entryId        The entry ID to search for.
     * @param int      $siteId         The site ID to search for.
     * @param int|null $limit          The limit on the number of versions to retrieve.
     * @param bool     $includeCurrent Whether to include the current "top" version of the entry.
     *
     * @return EntryVersion[]
     */
    public function getVersionsByEntryId(int $entryId, int $siteId, int $limit = null, bool $includeCurrent = false): array
    {
        if (!$siteId) {
            $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        }

        $versions = [];

        $results = (new Query())
            ->select([
                'id',
                'entryId',
                'sectionId',
                'creatorId',
                'siteId',
                'num',
                'notes',
                'data',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from(['{{%entryversions}}'])
            ->where(['entryId' => $entryId, 'siteId' => $siteId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->offset($includeCurrent ? 0 : 1)
            ->limit($limit)
            ->all();

        foreach ($results as $result) {
            $result['data'] = Json::decode($result['data']);

            // Don't initialize the content
            unset($result['data']['fields']);

            $versions[] = new EntryVersion($result);
        }

        return $versions;
    }

    /**
     * Saves a new version.
     *
     * @param Entry $entry
     *
     * @return bool
     */
    public function saveVersion(Entry $entry): bool
    {
        // Get the total number of existing versions for this entry/site
        $totalVersions = (new Query())
            ->from(['{{%entryversions}}'])
            ->where(['entryId' => $entry->id, 'siteId' => $entry->siteId])
            ->count('[[id]]');

        $versionRecord = new EntryVersionRecord();
        $versionRecord->entryId = $entry->id;
        $versionRecord->sectionId = $entry->sectionId;
        $versionRecord->creatorId = Craft::$app->getUser()->getIdentity() ? Craft::$app->getUser()->getIdentity()->id : $entry->authorId;
        $versionRecord->siteId = $entry->siteId;
        $versionRecord->num = $totalVersions + 1;
        $versionRecord->data = $this->_getRevisionData($entry);
        $versionRecord->notes = $entry->revisionNotes;

        return $versionRecord->save();
    }

    /**
     * Reverts an entry to a version.
     *
     * @param EntryVersion $version
     * @param bool         $runValidation Whether to perform validation
     *
     * @return bool
     */
    public function revertEntryToVersion(EntryVersion $version, bool $runValidation = true): bool
    {
        // If this is a single, we'll have to set the title manually
        if ($version->getSection()->type === Section::TYPE_SINGLE) {
            $version->title = $version->getSection()->name;
        }

        if ($runValidation && !$version->validate()) {
            Craft::info('Entry not reverted due to validation error.', __METHOD__);

            return false;
        }

        // Set the version notes
        $version->revisionNotes = Craft::t('app', 'Reverted version {num}.',
            ['num' => $version->num]);

        // Fire a 'beforeRevertEntryToVersion' event
        $this->trigger(self::EVENT_BEFORE_REVERT_ENTRY_TO_VERSION, new VersionEvent([
            'version' => $version,
        ]));

        // Revert the entry without re-running validation on it
        Craft::$app->getElements()->saveElement($version, false);

        // Fire an 'afterRevertEntryToVersion' event
        $this->trigger(self::EVENT_AFTER_REVERT_ENTRY_TO_VERSION, new VersionEvent([
            'version' => $version,
        ]));

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a draft record.
     *
     * @param EntryDraft $draft
     *
     * @return EntryDraftRecord
     * @throws EntryDraftNotFoundException if $draft->draftId is invalid
     */
    private function _getDraftRecord(EntryDraft $draft): EntryDraftRecord
    {
        if ($draft->draftId) {
            $draftRecord = EntryDraftRecord::findOne($draft->draftId);

            if (!$draftRecord) {
                throw new EntryDraftNotFoundException('Invalid entry draft ID: '.$draft->draftId);
            }
        } else {
            $draftRecord = new EntryDraftRecord();
            $draftRecord->entryId = $draft->id;
            $draftRecord->sectionId = $draft->sectionId;
            $draftRecord->creatorId = $draft->creatorId;
            $draftRecord->siteId = $draft->siteId;
        }

        return $draftRecord;
    }

    /**
     * Returns an array of all the revision data for a draft or version.
     *
     * @param Entry $revision
     *
     * @return array
     */
    private function _getRevisionData(Entry $revision): array
    {
        $revisionData = [
            'typeId' => $revision->typeId,
            'authorId' => $revision->authorId,
            'title' => $revision->title,
            'slug' => $revision->slug,
            'postDate' => $revision->postDate ? $revision->postDate->getTimestamp() : null,
            'expiryDate' => $revision->expiryDate ? $revision->expiryDate->getTimestamp() : null,
            'enabled' => $revision->enabled,
            'newParentId' => $revision->newParentId,
            'fields' => [],
        ];

        $content = $revision->getSerializedFieldValues();

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            /** @var Field $field */
            if (isset($content[$field->handle]) && $content[$field->handle] !== null) {
                $revisionData['fields'][$field->id] = $content[$field->handle];
            }
        }

        return $revisionData;
    }
}
