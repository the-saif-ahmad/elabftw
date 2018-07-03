<?php
/**
 * \Elabftw\Elabftw\Tags
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
declare(strict_types=1);

namespace Elabftw\Elabftw;


/**
 * All about the tag
 */
class Tags implements CrudInterface
{
    /** @var Db $Db SQL Database */
    protected $Db;

    /** @var AbstractEntity $Entity an instance of AbstractEntity */
    public $Entity;

    /**
     * Constructor
     *
     * @param AbstractEntity $entity
     */
    public function __construct(AbstractEntity $entity)
    {
        $this->Db = Db::getConnection();
        $this->Entity = $entity;
    }

    /**
     * Create a tag
     *
     * @param string $tag
     * @return bool
     */
    public function create(string $tag): bool
    {
        $insertSql2 = "INSERT INTO tags2entity (item_id, item_type, tag_id) VALUES (:item_id, :item_type, :tag_id)";
        $insertReq2 = $this->Db->prepare($insertSql2);
        // check if the tag doesn't exist already for the team
        $sql = "SELECT id FROM tags WHERE tag = :tag AND team = :team";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':tag', $tag);
        $req->bindParam(':team', $this->Entity->Users->userData['team']);
        $req->execute();
        $tagId = $req->fetchColumn();

        // tag doesn't exist already
        if ((int) $req->rowCount() === 0) {
            $insertSql = "INSERT INTO tags (team, tag) VALUES (:team, :tag)";
            $insertReq = $this->Db->prepare($insertSql);
            $insertReq->bindParam(':tag', $tag);
            $insertReq->bindParam(':team', $this->Entity->Users->userData['team']);
            $insertReq->execute();
            $tagId = $this->Db->lastInsertId();
        }
        // now reference it
        $insertReq2->bindParam(':item_id', $this->Entity->id);
        $insertReq2->bindParam(':item_type', $this->Entity->type);
        $insertReq2->bindParam(':tag_id', $tagId);

        return $insertReq2->execute();
    }

    /**
     * Read all the tags from team
     *
     * @param string|null $term The beginning of the input for tag autocomplete
     * @return array
     */
    public function readAll(?string $term = null): array
    {
        $tagFilter = "";
        if ($term !== null) {
            $tagFilter = " AND tags.tag LIKE '%$term%'";
        }
        $sql = "SELECT tag, id
            FROM tags
            WHERE team = :team
            $tagFilter
            ORDER BY tag ASC";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Entity->Users->userData['team']);
        $req->execute();

        return $req->fetchAll();
    }

    /**
     * Copy the tags from one experiment/item to an other.
     *
     * @param int $newId The id of the new experiment/item that will receive the tags
     * @return void
     */
    public function copyTags(int $newId): void
    {
        $sql = "SELECT tag_id FROM tags2entity WHERE item_id = :item_id AND item_type = :item_type";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':item_id', $this->Entity->id);
        $req->bindParam(':item_type', $this->Entity->type);
        $req->execute();
        if ($req->rowCount() > 0) {
            $insertSql = "INSERT INTO tags2entity (item_id, item_type, tag_id) VALUES (:item_id, :item_type, :tag_id)";
            $insertReq = $this->Db->prepare($insertSql);
            while ($tags = $req->fetch()) {
                $insertReq->bindParam(':item_id', $newId);
                $insertReq->bindParam(':item_type', $this->Entity->type);
                $insertReq->bindParam(':tag_id', $tags['tag_id']);
                $insertReq->execute();
            }
        }
    }

    /**
     * Get an array of tags starting with the query ($term)
     *
     * @param string $term the beginning of the tag
     * @return array the tag list filtered by the term
     */
    public function getList(string $term): array
    {
        $tagListArr = array();
        $tagsArr = $this->readAll($term);

        foreach ($tagsArr as $tag) {
            $tagListArr[] = $tag['tag'];
        }
        return $tagListArr;
    }

    /**
     * Get the tag list as option html tag for the search page. Will disappear in search.html once it exists...
     *
     * @param array $selected the selected tag(s)
     * @deprecated
     * @return string html for include in a select input
     */
    public function generateTagList(array $selected): string
    {
        $tagsArr = $this->readAll();

        $tagList = "";

        foreach ($tagsArr as $tag) {
            $tagList .= "<option value='" . $tag['tag'] . "'";
            if (in_array($tag['tag'], $selected, true)) {
                $tagList .= " selected='selected'";
            }
            $tagList .= ">" . $tag['tag'] . " (" . $tag['nbtag'] . ")</option>";
        }

        return $tagList;
    }

    /**
     * Update a tag
     *
     * @param string $tag tag value
     * @param string $newtag new tag value
     * @return bool
     */
    public function update(string $tag, string $newtag): bool
    {
        $sql = "UPDATE tags SET tag = :newtag WHERE tag = :tag AND team = :team";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':tag', $tag);
        $req->bindParam(':newtag', $newtag);
        $req->bindParam(':team', $this->Entity->Users->userData['team']);

        return $req->execute();
    }

    /**
     * Unreference a tag from an entity
     *
     * @param int $tagId id of the tag
     * @return bool
     */
    public function unreference(int $tagId): bool
    {
        $sql = "DELETE FROM tags2entity WHERE tag_id = :tag_id AND item_id = :item_id";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':tag_id', $tagId);
        $req->bindParam(':item_id', $this->Entity->id);

        $res1 = $req->execute();

        // now check if another entity is referencing it, if not, remove it from the tags table
        $sql = "SELECT tag_id FROM tags2entity WHERE tag_id = :tag_id";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':tag_id', $tagId);

        $res2 = $req->execute();
        $tags = $req->fetchAll();

        $res3 = true;
        if (\count($tags) === 0) {
            $res3 = $this->destroy((int) $tagId);
        }

        return $res1 && $res2 && $res3;
    }

    /**
     * Destroy a tag completely. Unreference it from everywhere and then delete it
     *
     * @param int $tagId id of the tag
     * @return bool
     */
    public function destroy(int $tagId): bool
    {
        // first unreference the tag
        $sql = "DELETE FROM tags2entity WHERE tag_id = :tag_id";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':tag_id', $tagId);
        $res1 = $req->execute();

        // now delete it from the tags table
        $sql = "DELETE FROM tags WHERE id = :tag_id";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':tag_id', $tagId);
        $res2 = $req->execute();

        return $res1 && $res2;
    }


    /**
     * Destroy all the tags for an item ID
     * Here the tag are not destroyed because it might be nice to keep the tags in memory
     * even when nothing is referencing it. Admin can manage tags anyway if it needs to be destroyed.
     *
     * @return bool
     */
    public function destroyAll(): bool
    {
        $sql = "DELETE FROM tags2entity WHERE item_id = :id";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->Entity->id);

        return $req->execute();
    }
}
