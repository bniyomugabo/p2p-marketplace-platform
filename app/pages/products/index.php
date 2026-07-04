/**
* Get attributes for a variant
*/
public function getAttributes($variantId)
{
$sql = "
SELECT * FROM variant_attributes
WHERE variant_id = :variant_id
ORDER BY display_order
";
$stmt = $this->db->prepare($sql);
$stmt->execute(['variant_id' => $variantId]);
return $stmt->fetchAll();
}

/**
* Add attribute to variant
*/
public function addAttribute($variantId, $attributeName, $attributeValue, $displayOrder = 0): bool
{
$sql = "
INSERT INTO variant_attributes
(variant_id, attribute_name, attribute_value, display_order)
VALUES (:variant_id, :attribute_name, :attribute_value, :display_order)
";
$stmt = $this->db->prepare($sql);
return $stmt->execute([
'variant_id' => $variantId,
'attribute_name' => $attributeName,
'attribute_value' => $attributeValue,
'display_order' => $displayOrder
]);
}


/**
* Remove all attributes from variant
*/
public function clearAttributes($variantId): bool
{
$sql = "DELETE FROM variant_attributes WHERE variant_id = :variant_id";
$stmt = $this->db->prepare($sql);
return $stmt->execute(['variant_id' => $variantId]);
}

/**
* Remove image from variant
*/
public function removeImage($imageId): bool
{
$sql = "DELETE FROM variant_images WHERE id = :id";
$stmt = $this->db->prepare($sql);
return $stmt->execute(['id' => $imageId]);
}


    /**
     * Add image to variant
     */
    public function addImage($variantId, $imageUrl, $isPrimary = false, $caption = null)
    {
        // If this is primary, unset other primary images
        if ($isPrimary) {
            $sql = "UPDATE variant_images SET is_primary = 0 WHERE variant_id = :variant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['variant_id' => $variantId]);
        }

        // Get sort order
        $sql = "SELECT COALESCE(MAX(sort_order), -1) + 1 as next_order FROM variant_images WHERE variant_id = :variant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['variant_id' => $variantId]);
        $result = $stmt->fetch();
        $sortOrder = $result['next_order'] ?? 0;

        // Insert new image
        $sql = "
            INSERT INTO variant_images 
            (variant_id, image_url, is_primary, sort_order, caption)
            VALUES (:variant_id, :image_url, :is_primary, :sort_order, :caption)
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'variant_id' => $variantId,
            'image_url' => $imageUrl,
            'is_primary' => $isPrimary ? 1 : 0,
            'sort_order' => $sortOrder,
            'caption' => $caption
        ]);
    }

    /**
     * Get variant images
     */
    public function getImages($variantId)
    {
        $sql = "
            SELECT * FROM variant_images 
            WHERE variant_id = :variant_id 
            ORDER BY is_primary DESC, sort_order ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['variant_id' => $variantId]);
        return $stmt->fetchAll();
    }


    /**
     * Set primary image
     */
    public function setPrimaryImage($variantId, $imageId): bool
    {
        try {
            $this->beginTransaction();

            // Unset all primary
            $sql1 = "UPDATE variant_images SET is_primary = 0 WHERE variant_id = :variant_id";
            $stmt1 = $this->db->prepare($sql1);
            $stmt1->execute(['variant_id' => $variantId]);

            // Set new primary
            $sql2 = "UPDATE variant_images SET is_primary = 1 WHERE id = :id AND variant_id = :variant_id";
            $stmt2 = $this->db->prepare($sql2);
            $result = $stmt2->execute([
                'id' => $imageId,
                'variant_id' => $variantId
            ]);

            $this->commit();
            return $result;

        } catch (Exception $e) {
            $this->rollback();
            error_log("Set primary image error: " . $e->getMessage());
            throw $e;
        }
    }

