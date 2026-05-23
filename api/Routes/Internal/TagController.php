<?php

namespace Routes\Internal;

use Psr\Container\ContainerInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Gallery\Core\ResponseCache;
use Gallery\Collection\TagCollection;
use Gallery\Collection\MediaCollection;
use Gallery\Structure\Tag;

/**
 * TagController class
 * Handles tag-related API requests with unified media support.
 */
class TagController extends AbstractController
{
    private const int MAX_TAG_NAME_LENGTH = 100;
    private const array VALID_CATEGORY_IDS = [1, 2, 3, 4, 5];

    private TagCollection $tag_collection;
    private MediaCollection $media_collection;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->tag_collection = new TagCollection();
        $this->media_collection = new MediaCollection();
    }

    public function getTag(Request $request, Response $response, array $args): Response
    {
        $tag_id = $this->parseParameters($args, 'tag_id', null);

        if ($tag_id === null) {
            return $this->error($response, 'NoTagIDProvided', 400, 'A tag ID is required.');
        }

        if (!is_numeric($tag_id) || $tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'The tag ID must be a positive number.');
        }

        $data = $this->tag_collection->get((int)$tag_id);
        return $this->success($response, $data);
    }

    public function getAllTags(Request $request, Response $response, array $args): Response
    {
        return $this->cachedSuccess($response, 'tags', 'all', ResponseCache::TTL_MEDIUM, function () {
            return $this->tag_collection->getAll();
        });
    }

    public function getTagListForDisplay(Request $request, Response $response, array $args): Response
    {
        return $this->cachedSuccess($response, 'tags', 'display', ResponseCache::TTL_MEDIUM, function () {
            return $this->tag_collection->getAllForPage();
        });
    }

    public function addTag(Request $request, Response $response, array $args): Response
    {
        $params = $request->getParsedBody();
        $tag_name = $this->sanitizeTagName($this->parseParameters($params, 'tag_name', ''));
        $tag_category = (int)$this->parseParameters($params, 'category_id', 1);

        if (empty($tag_name)) {
            return $this->error($response, 'InvalidTagName', 400, 'Tag name cannot be empty.');
        }
        if (mb_strlen($tag_name) > self::MAX_TAG_NAME_LENGTH) {
            return $this->error($response, 'TagNameTooLong', 400, 'Tag name must be ' . self::MAX_TAG_NAME_LENGTH . ' characters or fewer.');
        }
        if (!in_array($tag_category, self::VALID_CATEGORY_IDS, true)) {
            return $this->error($response, 'InvalidCategoryID', 400, 'The selected tag category is not valid.');
        }
        if ($this->tag_collection->getByName($tag_name) instanceof Tag) {
            return $this->error($response, 'TagAlreadyExists', 400, "A tag named \"{$tag_name}\" already exists.");
        }

        $tag = new Tag();
        $tag->setTagName($tag_name)->setCategoryId($tag_category);
        $tag_id = $this->tag_collection->save($tag);

        if ($tag_id === 0) {
            $this->logger->error('Failed to create tag', ['tag_name' => $tag_name]);
            return $this->error($response, 'CouldNotCreateTag', 500, 'The tag could not be created. Please try again.');
        }

        $this->invalidateCache('tags');
        $this->logger->info('Tag created', ['tag_id' => $tag_id, 'tag_name' => $tag_name, 'category_id' => $tag_category]);
        return $this->success($response, true);
    }

    public function editTag(Request $request, Response $response, array $args): Response
    {
        $params = $request->getParsedBody();
        $tag_id = (int)$this->parseParameters($args, 'tag_id', 0);
        $tag_name = $this->sanitizeTagName($this->parseParameters($params, 'tag_name', ''));
        $tag_category = (int)$this->parseParameters($params, 'category_id', 1);

        if ($tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'The tag ID must be a positive number.');
        }
        if (empty($tag_name)) {
            return $this->error($response, 'InvalidTagName', 400, 'Tag name cannot be empty.');
        }
        if (mb_strlen($tag_name) > self::MAX_TAG_NAME_LENGTH) {
            return $this->error($response, 'TagNameTooLong', 400, 'Tag name must be ' . self::MAX_TAG_NAME_LENGTH . ' characters or fewer.');
        }
        if (!in_array($tag_category, self::VALID_CATEGORY_IDS, true)) {
            return $this->error($response, 'InvalidCategoryID', 400, 'The selected tag category is not valid.');
        }

        $tag = $this->tag_collection->get($tag_id);
        if (!($tag instanceof Tag)) {
            return $this->error($response, 'TagDoesNotExist', 404, 'The tag to edit could not be found.');
        }

        $tag->setTagName($tag_name)->setCategoryId($tag_category);
        $saved_id = $this->tag_collection->save($tag);

        if ($saved_id === 0) {
            $this->logger->error('Failed to save tag edit', ['tag_id' => $tag_id]);
            return $this->error($response, 'CouldNotSaveTag', 500, 'The tag changes could not be saved. Please try again.');
        }

        $this->invalidateCache('tags');
        $this->logger->info('Tag edited', ['tag_id' => $tag_id, 'tag_name' => $tag_name, 'category_id' => $tag_category]);
        return $this->success($response, true);
    }

    /**
     * Get tags for a media item by ID.
     */
    public function getTagsForMedia(Request $request, Response $response, array $args): Response
    {
        $media_id = $this->parseParameters($args, 'media_id', 0);

        if (!is_numeric($media_id) || $media_id <= 0) {
            return $this->error($response, 'InvalidMediaID', 400, 'The media ID must be a positive number.');
        }

        $media = $this->media_collection->get((int)$media_id);

        if ($media === null) {
            return $this->error($response, 'MediaDoesNotExist', 404, 'The media item could not be found.');
        }

        return $this->cachedSuccess($response, 'tags', "for:media:{$media_id}", ResponseCache::TTL_SHORT, function () use ($media) {
            return $this->tag_collection->getTagsForMedia($media);
        });
    }

    /**
     * Add tags to a media item.
     */
    public function addTagsToMedia(Request $request, Response $response, array $args): Response
    {
        $params = $request->getParsedBody();
        $media_id = (int)$this->parseParameters($params, 'item_id', 0);
        $tag_ids_raw = $this->parseParameters($params, 'tag_ids', []);

        if ($media_id <= 0) {
            return $this->error($response, 'InvalidMediaID', 400, 'The media ID must be a positive number.');
        }

        $media = $this->media_collection->get($media_id);

        if ($media === null) {
            return $this->error($response, 'MediaDoesNotExist', 404, 'The media item could not be found.');
        }

        $tag_ids = array_filter(array_map('intval', (array)$tag_ids_raw), fn($id) => $id > 0);

        if (empty($tag_ids)) {
            return $this->error($response, 'InvalidTagList', 400, 'At least one valid tag must be provided.');
        }

        foreach ($tag_ids as $tid) {
            $tag = $this->tag_collection->get($tid);
            if (!($tag instanceof Tag)) {
                return $this->error($response, 'TagDoesNotExist', 404, "Tag #{$tid} does not exist.");
            }
        }

        $success = $this->tag_collection->addTagsToMedia($media, $tag_ids);

        if (!$success) {
            $this->logger->error('Failed to add tags to media', ['media_id' => $media_id, 'tag_ids' => $tag_ids]);
            return $this->error($response, 'CouldNotAddTagsToMedia', 500, 'Failed to add tags to the media item. Please try again.');
        }

        $this->invalidateCache('media', 'tags');
        $this->logger->info('Tags added to media', ['media_id' => $media_id, 'tag_ids' => $tag_ids]);

        $data = $this->tag_collection->getTagsForMedia($media);
        return $this->success($response, $data);
    }

    /**
     * Remove a tag from a media item.
     */
    public function removeTagFromMedia(Request $request, Response $response, array $args): Response
    {
        $params = $request->getParsedBody();
        $media_id = (int)$this->parseParameters($params, 'item_id', 0);
        $tag_id = (int)$this->parseParameters($params, 'tag_id', 0);

        if ($media_id <= 0) {
            return $this->error($response, 'InvalidMediaID', 400, 'The media ID must be a positive number.');
        }

        $media = $this->media_collection->get($media_id);

        if ($media === null) {
            return $this->error($response, 'MediaDoesNotExist', 404, 'The media item could not be found.');
        }

        if ($tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'The tag ID must be a positive number.');
        }

        $tag = $this->tag_collection->get($tag_id);
        if (!($tag instanceof Tag)) {
            return $this->error($response, 'CouldNotFindTag', 404, 'The tag to remove could not be found.');
        }

        $removed = $this->tag_collection->removeTagFromMedia($media, $tag);

        if (!$removed) {
            $this->logger->error('Failed to remove tag from media', ['media_id' => $media_id, 'tag_id' => $tag_id]);
            return $this->error($response, 'CouldNotRemoveTagFromMedia', 500, 'Failed to remove the tag. Please try again.');
        }

        $this->invalidateCache('media', 'tags');
        $this->logger->info('Tag removed from media', ['media_id' => $media_id, 'tag_id' => $tag_id]);

        $data = $this->tag_collection->getTagsForMedia($media);
        return $this->success($response, $data);
    }

    public function migrateTag(Request $request, Response $response, array $args): Response
    {
        $params = $request->getParsedBody();
        $source_id = (int)$this->parseParameters($params, 'source_tag_id', 0);
        $target_id = (int)$this->parseParameters($params, 'target_tag_id', 0);

        if ($source_id <= 0 || $target_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'Both source and target tag IDs must be positive numbers.');
        }

        if ($source_id === $target_id) {
            return $this->error($response, 'CannotMigrateToSelf', 400, 'A tag cannot be migrated to itself.');
        }

        $sourceTag = $this->tag_collection->get($source_id);
        $targetTag = $this->tag_collection->get($target_id);

        if (!($sourceTag instanceof Tag) || !($targetTag instanceof Tag)) {
            return $this->error($response, 'TagDoesNotExist', 404, 'The source or target tag could not be found.');
        }

        $success = $this->tag_collection->migrateTag($sourceTag, $targetTag);

        if (!$success) {
            $this->logger->error('Failed to migrate tag', ['source' => $source_id, 'target' => $target_id]);
            return $this->error($response, 'CouldNotMigrateTag', 500, 'Tag migration failed. Please try again.');
        }

        $this->invalidateCache('tags', 'media');
        $this->logger->info('Tag migrated', ['source' => $source_id, 'target' => $target_id]);
        return $this->success($response, true);
    }

    public function deleteTag(Request $request, Response $response, array $args): Response
    {
        $params = $request->getParsedBody();
        $tag_id = (int)$this->parseParameters($params, 'tag_id', 0);
        $migrate_to_id = (int)$this->parseParameters($params, 'migrate_to_tag_id', 0);

        if ($tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'The tag ID must be a positive number.');
        }

        $tag = $this->tag_collection->get($tag_id);
        if (!($tag instanceof Tag)) {
            return $this->error($response, 'TagDoesNotExist', 404, 'The tag to delete could not be found.');
        }

        if ($migrate_to_id > 0) {
            if ($migrate_to_id === $tag_id) {
                return $this->error($response, 'CannotMigrateToSelf', 400, 'A tag cannot be migrated to itself.');
            }

            $targetTag = $this->tag_collection->get($migrate_to_id);
            if (!($targetTag instanceof Tag)) {
                return $this->error($response, 'MigrationTargetDoesNotExist', 404, 'The migration target tag could not be found.');
            }

            $migrated = $this->tag_collection->migrateTag($tag, $targetTag);
            if (!$migrated) {
                $this->logger->error('Failed to migrate before delete', ['tag_id' => $tag_id, 'target' => $migrate_to_id]);
                return $this->error($response, 'CouldNotMigrateTag', 500, 'Tag migration failed before deletion. Nothing was deleted.');
            }
        }

        $deleted = $this->tag_collection->delete($tag);
        if (!$deleted) {
            $this->logger->error('Failed to delete tag', ['tag_id' => $tag_id]);
            return $this->error($response, 'CouldNotDeleteTag', 500, 'The tag could not be deleted. Please try again.');
        }

        $this->invalidateCache('tags', 'media');
        $this->logger->info('Tag deleted', ['tag_id' => $tag_id, 'migrated_to' => $migrate_to_id]);
        return $this->success($response, true);
    }

    // ========================================================================
    // Tag Implication Endpoints
    // ========================================================================

    public function getImplications(Request $request, Response $response, array $args): Response
    {
        return $this->cachedSuccess($response, 'tags', 'implications', ResponseCache::TTL_MEDIUM, function () {
            return $this->tag_collection->getAllImplications();
        });
    }

    public function addImplication(Request $request, Response $response, array $args): Response
    {
        $params = $request->getParsedBody();
        $tag_id = (int)$this->parseParameters($params, 'tag_id', 0);
        $implied_tag_id = (int)$this->parseParameters($params, 'implied_tag_id', 0);

        if ($tag_id <= 0 || $implied_tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'Both tag IDs must be positive numbers.');
        }

        if ($tag_id === $implied_tag_id) {
            return $this->error($response, 'CannotImplySelf', 400, 'A tag cannot imply itself.');
        }

        $tag = $this->tag_collection->get($tag_id);
        $impliedTag = $this->tag_collection->get($implied_tag_id);

        if (!($tag instanceof Tag) || !($impliedTag instanceof Tag)) {
            return $this->error($response, 'TagDoesNotExist', 404, 'One or both of the specified tags could not be found.');
        }

        $success = $this->tag_collection->addImplication($tag_id, $implied_tag_id);

        if (!$success) {
            return $this->error($response, 'CycleDetected', 400, 'This implication would create a circular dependency.');
        }

        $this->invalidateCache('tags');
        $this->logger->info('Tag implication added', ['tag_id' => $tag_id, 'implied_tag_id' => $implied_tag_id]);
        return $this->success($response, $this->tag_collection->getAllImplications());
    }

    public function removeImplication(Request $request, Response $response, array $args): Response
    {
        $params = $request->getParsedBody();
        $tag_id = (int)$this->parseParameters($params, 'tag_id', 0);
        $implied_tag_id = (int)$this->parseParameters($params, 'implied_tag_id', 0);

        if ($tag_id <= 0 || $implied_tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'Both tag IDs must be positive numbers.');
        }

        $success = $this->tag_collection->removeImplication($tag_id, $implied_tag_id);

        if (!$success) {
            $this->logger->error('Failed to remove tag implication', ['tag_id' => $tag_id, 'implied_tag_id' => $implied_tag_id]);
            return $this->error($response, 'CouldNotRemoveImplication', 500, 'The implication could not be removed. Please try again.');
        }

        $this->invalidateCache('tags');
        $this->logger->info('Tag implication removed', ['tag_id' => $tag_id, 'implied_tag_id' => $implied_tag_id]);
        return $this->success($response, $this->tag_collection->getAllImplications());
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    private function sanitizeTagName(string $tag_name): string
    {
        $tag_name = trim($tag_name);
        $tag_name = mb_strtolower($tag_name, 'UTF-8');
        $tag_name = strip_tags($tag_name);
        $tag_name = preg_replace('/[\x00-\x1F\x7F]/u', '', $tag_name);
        $tag_name = ltrim($tag_name, '-');

        return $tag_name;
    }
}
