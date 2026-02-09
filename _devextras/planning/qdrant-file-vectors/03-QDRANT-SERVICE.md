# 03 - Qdrant Service Extensions

## Overview

Extend the existing `qdrant-service` (synaplan-memories) to support document storage alongside user memories.

**Key Principle:** Reuse the existing service architecture, just add new endpoints and collection.

---

## New Collection: `user_documents`

### Collection Configuration

```rust
// config.rs - Add new environment variable
pub struct Config {
    // Existing
    pub qdrant_url: String,
    pub collection_name: String,  // user_memories
    pub service_api_key: Option<String>,
    pub vector_size: usize,

    // New
    pub documents_collection_name: String,  // user_documents
}

impl Config {
    pub fn from_env() -> Self {
        Self {
            // Existing...
            documents_collection_name: std::env::var("QDRANT_DOCUMENTS_COLLECTION_NAME")
                .unwrap_or_else(|_| "user_documents".to_string()),
        }
    }
}
```

### Collection Schema

```rust
// On startup, ensure documents collection exists
async fn ensure_documents_collection(client: &QdrantClient, config: &Config) -> Result<()> {
    let collection_name = &config.documents_collection_name;

    if !client.collection_exists(collection_name).await? {
        client.create_collection(&CreateCollection {
            collection_name: collection_name.clone(),
            vectors_config: Some(VectorsConfig {
                config: Some(vectors_config::Config::Params(VectorParams {
                    size: config.vector_size as u64,  // 1024
                    distance: Distance::Cosine.into(),
                    hnsw_config: Some(HnswConfigDiff {
                        m: Some(16),           // Higher for better recall
                        ef_construct: Some(100), // Better index quality
                        ..Default::default()
                    }),
                    ..Default::default()
                })),
            }),
            // Payload indexes for efficient filtering
            ..Default::default()
        }).await?;

        // Create payload indexes for common filters
        client.create_field_index(
            collection_name,
            "user_id",
            FieldType::Integer,
            None,
            None,
        ).await?;

        client.create_field_index(
            collection_name,
            "file_id",
            FieldType::Integer,
            None,
            None,
        ).await?;

        client.create_field_index(
            collection_name,
            "group_key",
            FieldType::Keyword,
            None,
            None,
        ).await?;

        info!("Created documents collection: {}", collection_name);
    }

    Ok(())
}
```

---

## New Models

```rust
// models.rs - Add document-related structures

use serde::{Deserialize, Serialize};
use utoipa::ToSchema;

/// Document chunk payload stored in Qdrant
#[derive(Debug, Clone, Serialize, Deserialize, ToSchema)]
pub struct DocumentPayload {
    /// User ID for multi-tenant isolation
    pub user_id: i64,
    /// Reference to source file (BFILES.BID)
    pub file_id: i64,
    /// Grouping key (e.g., "WIDGET:xxx", "TASKPROMPT:xxx", "DEFAULT")
    pub group_key: String,
    /// File type identifier
    pub file_type: i32,
    /// Chunk position in file
    pub chunk_index: i32,
    /// Source line start
    pub start_line: i32,
    /// Source line end
    pub end_line: i32,
    /// Chunk text content
    pub text: String,
    /// Unix timestamp
    pub created: i64,
}

/// Request to upsert a document chunk
#[derive(Debug, Deserialize, ToSchema)]
pub struct UpsertDocumentRequest {
    /// Unique point ID (e.g., "doc_1_123_0")
    pub point_id: String,
    /// Vector embedding (must be exactly 1024 dimensions)
    pub vector: Vec<f32>,
    /// Document payload
    pub payload: DocumentPayload,
}

/// Request for batch document upsert
#[derive(Debug, Deserialize, ToSchema)]
pub struct BatchUpsertDocumentsRequest {
    /// Array of documents to upsert (max 100)
    pub documents: Vec<UpsertDocumentRequest>,
}

/// Response for batch operations
#[derive(Debug, Serialize, ToSchema)]
pub struct BatchUpsertResponse {
    pub success_count: usize,
    pub failed_count: usize,
    pub errors: Vec<String>,
}

/// Request to search documents
#[derive(Debug, Deserialize, ToSchema)]
pub struct SearchDocumentsRequest {
    /// Query vector (must be exactly 1024 dimensions)
    pub vector: Vec<f32>,
    /// User ID (required for isolation)
    pub user_id: i64,
    /// Optional group key filter
    #[serde(default)]
    pub group_key: Option<String>,
    /// Maximum results (default: 10)
    #[serde(default = "default_limit")]
    pub limit: u64,
    /// Minimum similarity score (default: 0.3)
    #[serde(default = "default_min_score")]
    pub min_score: f32,
}

fn default_limit() -> u64 { 10 }
fn default_min_score() -> f32 { 0.3 }

/// Search result
#[derive(Debug, Serialize, ToSchema)]
pub struct DocumentSearchResult {
    /// Point ID
    pub id: String,
    /// Similarity score (0.0 - 1.0)
    pub score: f32,
    /// Document payload
    pub payload: DocumentPayload,
}

/// Request to delete documents by file
#[derive(Debug, Deserialize, ToSchema)]
pub struct DeleteByFileRequest {
    pub user_id: i64,
    pub file_id: i64,
}

/// Request to delete documents by group key
#[derive(Debug, Deserialize, ToSchema)]
pub struct DeleteByGroupKeyRequest {
    pub user_id: i64,
    pub group_key: String,
}

/// Request to update group key
#[derive(Debug, Deserialize, ToSchema)]
pub struct UpdateGroupKeyRequest {
    pub user_id: i64,
    pub file_id: i64,
    pub new_group_key: String,
}

/// Document statistics response
#[derive(Debug, Serialize, ToSchema)]
pub struct DocumentStatsResponse {
    pub total_chunks: u64,
    pub total_files: u64,
    pub total_groups: u64,
    pub chunks_by_group: std::collections::HashMap<String, u64>,
}
```

---

## New Handlers

```rust
// handlers.rs - Add document handlers

use axum::{
    extract::{Path, Query, State},
    http::StatusCode,
    Json,
};
use std::sync::Arc;

use crate::{
    models::*,
    qdrant::QdrantClient,
    error::AppError,
};

/// Upsert a single document chunk
#[utoipa::path(
    post,
    path = "/documents",
    request_body = UpsertDocumentRequest,
    responses(
        (status = 200, description = "Document upserted successfully"),
        (status = 400, description = "Invalid request"),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn upsert_document(
    State(client): State<Arc<QdrantClient>>,
    Json(req): Json<UpsertDocumentRequest>,
) -> Result<StatusCode, AppError> {
    // Validate vector dimension
    if req.vector.len() != 1024 {
        return Err(AppError::BadRequest(format!(
            "Vector must have exactly 1024 dimensions, got {}",
            req.vector.len()
        )));
    }

    client.upsert_document(&req.point_id, &req.vector, &req.payload).await?;

    Ok(StatusCode::OK)
}

/// Batch upsert document chunks
#[utoipa::path(
    post,
    path = "/documents/batch",
    request_body = BatchUpsertDocumentsRequest,
    responses(
        (status = 200, description = "Batch upsert completed", body = BatchUpsertResponse),
        (status = 400, description = "Invalid request"),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn batch_upsert_documents(
    State(client): State<Arc<QdrantClient>>,
    Json(req): Json<BatchUpsertDocumentsRequest>,
) -> Result<Json<BatchUpsertResponse>, AppError> {
    if req.documents.len() > 100 {
        return Err(AppError::BadRequest("Maximum 100 documents per batch".into()));
    }

    let mut success_count = 0;
    let mut failed_count = 0;
    let mut errors = Vec::new();

    for doc in &req.documents {
        if doc.vector.len() != 1024 {
            failed_count += 1;
            errors.push(format!("Document {}: invalid vector dimension", doc.point_id));
            continue;
        }

        match client.upsert_document(&doc.point_id, &doc.vector, &doc.payload).await {
            Ok(_) => success_count += 1,
            Err(e) => {
                failed_count += 1;
                errors.push(format!("Document {}: {}", doc.point_id, e));
            }
        }
    }

    Ok(Json(BatchUpsertResponse {
        success_count,
        failed_count,
        errors,
    }))
}

/// Search documents by vector similarity
#[utoipa::path(
    post,
    path = "/documents/search",
    request_body = SearchDocumentsRequest,
    responses(
        (status = 200, description = "Search results", body = Vec<DocumentSearchResult>),
        (status = 400, description = "Invalid request"),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn search_documents(
    State(client): State<Arc<QdrantClient>>,
    Json(req): Json<SearchDocumentsRequest>,
) -> Result<Json<Vec<DocumentSearchResult>>, AppError> {
    if req.vector.len() != 1024 {
        return Err(AppError::BadRequest(format!(
            "Vector must have exactly 1024 dimensions, got {}",
            req.vector.len()
        )));
    }

    let results = client.search_documents(
        &req.vector,
        req.user_id,
        req.group_key.as_deref(),
        req.limit,
        req.min_score,
    ).await?;

    Ok(Json(results))
}

/// Get document by ID
#[utoipa::path(
    get,
    path = "/documents/{point_id}",
    params(
        ("point_id" = String, Path, description = "Document point ID")
    ),
    responses(
        (status = 200, description = "Document found", body = DocumentSearchResult),
        (status = 404, description = "Document not found"),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn get_document(
    State(client): State<Arc<QdrantClient>>,
    Path(point_id): Path<String>,
) -> Result<Json<DocumentSearchResult>, AppError> {
    let doc = client.get_document(&point_id).await?
        .ok_or_else(|| AppError::NotFound("Document not found".into()))?;

    Ok(Json(doc))
}

/// Delete document by ID
#[utoipa::path(
    delete,
    path = "/documents/{point_id}",
    params(
        ("point_id" = String, Path, description = "Document point ID")
    ),
    responses(
        (status = 200, description = "Document deleted"),
        (status = 404, description = "Document not found"),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn delete_document(
    State(client): State<Arc<QdrantClient>>,
    Path(point_id): Path<String>,
) -> Result<StatusCode, AppError> {
    client.delete_document(&point_id).await?;
    Ok(StatusCode::OK)
}

/// Delete all documents for a file
#[utoipa::path(
    post,
    path = "/documents/delete-by-file",
    request_body = DeleteByFileRequest,
    responses(
        (status = 200, description = "Documents deleted", body = u64),
        (status = 400, description = "Invalid request"),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn delete_by_file(
    State(client): State<Arc<QdrantClient>>,
    Json(req): Json<DeleteByFileRequest>,
) -> Result<Json<u64>, AppError> {
    let deleted = client.delete_documents_by_file(req.user_id, req.file_id).await?;
    Ok(Json(deleted))
}

/// Delete all documents for a group key
#[utoipa::path(
    post,
    path = "/documents/delete-by-group",
    request_body = DeleteByGroupKeyRequest,
    responses(
        (status = 200, description = "Documents deleted", body = u64),
        (status = 400, description = "Invalid request"),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn delete_by_group_key(
    State(client): State<Arc<QdrantClient>>,
    Json(req): Json<DeleteByGroupKeyRequest>,
) -> Result<Json<u64>, AppError> {
    let deleted = client.delete_documents_by_group_key(req.user_id, &req.group_key).await?;
    Ok(Json(deleted))
}

/// Delete all documents for a user
#[utoipa::path(
    delete,
    path = "/documents/user/{user_id}",
    params(
        ("user_id" = i64, Path, description = "User ID")
    ),
    responses(
        (status = 200, description = "All user documents deleted", body = u64),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn delete_all_for_user(
    State(client): State<Arc<QdrantClient>>,
    Path(user_id): Path<i64>,
) -> Result<Json<u64>, AppError> {
    let deleted = client.delete_all_documents_for_user(user_id).await?;
    Ok(Json(deleted))
}

/// Update group key for all chunks of a file
#[utoipa::path(
    post,
    path = "/documents/update-group-key",
    request_body = UpdateGroupKeyRequest,
    responses(
        (status = 200, description = "Group key updated", body = u64),
        (status = 400, description = "Invalid request"),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn update_group_key(
    State(client): State<Arc<QdrantClient>>,
    Json(req): Json<UpdateGroupKeyRequest>,
) -> Result<Json<u64>, AppError> {
    let updated = client.update_document_group_key(
        req.user_id,
        req.file_id,
        &req.new_group_key,
    ).await?;
    Ok(Json(updated))
}

/// Get document statistics for a user
#[utoipa::path(
    get,
    path = "/documents/stats/{user_id}",
    params(
        ("user_id" = i64, Path, description = "User ID")
    ),
    responses(
        (status = 200, description = "Document statistics", body = DocumentStatsResponse),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn get_document_stats(
    State(client): State<Arc<QdrantClient>>,
    Path(user_id): Path<i64>,
) -> Result<Json<DocumentStatsResponse>, AppError> {
    let stats = client.get_document_stats(user_id).await?;
    Ok(Json(stats))
}

/// Get distinct group keys for a user
#[utoipa::path(
    get,
    path = "/documents/groups/{user_id}",
    params(
        ("user_id" = i64, Path, description = "User ID")
    ),
    responses(
        (status = 200, description = "Group keys", body = Vec<String>),
        (status = 401, description = "Unauthorized"),
    ),
    tag = "documents"
)]
pub async fn get_group_keys(
    State(client): State<Arc<QdrantClient>>,
    Path(user_id): Path<i64>,
) -> Result<Json<Vec<String>>, AppError> {
    let groups = client.get_document_group_keys(user_id).await?;
    Ok(Json(groups))
}
```

---

## Qdrant Client Extensions

```rust
// qdrant.rs - Add document operations

impl QdrantClient {
    /// Upsert a single document
    pub async fn upsert_document(
        &self,
        point_id: &str,
        vector: &[f32],
        payload: &DocumentPayload,
    ) -> Result<()> {
        let collection = &self.config.documents_collection_name;
        let numeric_id = self.hash_string_id(point_id);

        let mut payload_map = serde_json::to_value(payload)?
            .as_object()
            .cloned()
            .unwrap_or_default();

        // Store original string ID for retrieval
        payload_map.insert("_point_id".to_string(), json!(point_id));

        let point = PointStruct::new(
            numeric_id,
            vector.to_vec(),
            payload_map.into_iter().map(|(k, v)| (k, v.into())).collect(),
        );

        self.client
            .upsert_points_blocking(collection, None, vec![point], None)
            .await?;

        Ok(())
    }

    /// Search documents with user isolation
    pub async fn search_documents(
        &self,
        query_vector: &[f32],
        user_id: i64,
        group_key: Option<&str>,
        limit: u64,
        min_score: f32,
    ) -> Result<Vec<DocumentSearchResult>> {
        let collection = &self.config.documents_collection_name;

        // Build filter - ALWAYS filter by user_id for isolation
        let mut conditions = vec![
            Condition::matches("user_id", user_id),
        ];

        if let Some(gk) = group_key {
            conditions.push(Condition::matches("group_key", gk.to_string()));
        }

        let filter = Filter::must(conditions);

        let results = self.client
            .search_points(&SearchPoints {
                collection_name: collection.clone(),
                vector: query_vector.to_vec(),
                filter: Some(filter),
                limit,
                with_payload: Some(true.into()),
                score_threshold: Some(min_score),
                ..Default::default()
            })
            .await?;

        Ok(results.result
            .into_iter()
            .map(|p| {
                let payload: DocumentPayload = serde_json::from_value(
                    serde_json::Value::Object(
                        p.payload.into_iter()
                            .map(|(k, v)| (k, v.into()))
                            .collect()
                    )
                ).unwrap_or_default();

                DocumentSearchResult {
                    id: p.payload.get("_point_id")
                        .and_then(|v| v.as_str())
                        .unwrap_or("")
                        .to_string(),
                    score: p.score,
                    payload,
                }
            })
            .collect())
    }

    /// Delete documents by file
    pub async fn delete_documents_by_file(
        &self,
        user_id: i64,
        file_id: i64,
    ) -> Result<u64> {
        let collection = &self.config.documents_collection_name;

        let filter = Filter::must(vec![
            Condition::matches("user_id", user_id),
            Condition::matches("file_id", file_id),
        ]);

        let result = self.client
            .delete_points(collection, None, &filter.into(), None)
            .await?;

        Ok(result.result.map(|r| r.status).unwrap_or(0) as u64)
    }

    /// Delete documents by group key
    pub async fn delete_documents_by_group_key(
        &self,
        user_id: i64,
        group_key: &str,
    ) -> Result<u64> {
        let collection = &self.config.documents_collection_name;

        let filter = Filter::must(vec![
            Condition::matches("user_id", user_id),
            Condition::matches("group_key", group_key.to_string()),
        ]);

        let result = self.client
            .delete_points(collection, None, &filter.into(), None)
            .await?;

        Ok(result.result.map(|r| r.status).unwrap_or(0) as u64)
    }

    /// Delete all documents for a user
    pub async fn delete_all_documents_for_user(&self, user_id: i64) -> Result<u64> {
        let collection = &self.config.documents_collection_name;

        let filter = Filter::must(vec![
            Condition::matches("user_id", user_id),
        ]);

        let result = self.client
            .delete_points(collection, None, &filter.into(), None)
            .await?;

        Ok(result.result.map(|r| r.status).unwrap_or(0) as u64)
    }

    /// Update group key for file documents
    pub async fn update_document_group_key(
        &self,
        user_id: i64,
        file_id: i64,
        new_group_key: &str,
    ) -> Result<u64> {
        let collection = &self.config.documents_collection_name;

        let filter = Filter::must(vec![
            Condition::matches("user_id", user_id),
            Condition::matches("file_id", file_id),
        ]);

        // Qdrant's set_payload to update group_key
        self.client
            .set_payload(
                collection,
                None,
                &filter.into(),
                json!({"group_key": new_group_key}).as_object().unwrap().clone()
                    .into_iter()
                    .map(|(k, v)| (k, v.into()))
                    .collect(),
                None,
            )
            .await?;

        // Return count (estimate)
        Ok(1) // Note: Qdrant doesn't return count for set_payload
    }

    /// Get document statistics
    pub async fn get_document_stats(&self, user_id: i64) -> Result<DocumentStatsResponse> {
        let collection = &self.config.documents_collection_name;

        // Scroll through all user documents to calculate stats
        let filter = Filter::must(vec![
            Condition::matches("user_id", user_id),
        ]);

        let mut total_chunks = 0u64;
        let mut file_ids = std::collections::HashSet::new();
        let mut chunks_by_group: std::collections::HashMap<String, u64> = std::collections::HashMap::new();

        let mut offset = None;
        loop {
            let results = self.client
                .scroll(&ScrollPoints {
                    collection_name: collection.clone(),
                    filter: Some(filter.clone()),
                    limit: Some(1000),
                    offset,
                    with_payload: Some(true.into()),
                    with_vectors: Some(false.into()),
                    ..Default::default()
                })
                .await?;

            for point in &results.result {
                total_chunks += 1;

                if let Some(file_id) = point.payload.get("file_id").and_then(|v| v.as_i64()) {
                    file_ids.insert(file_id);
                }

                if let Some(group_key) = point.payload.get("group_key").and_then(|v| v.as_str()) {
                    *chunks_by_group.entry(group_key.to_string()).or_insert(0) += 1;
                }
            }

            offset = results.next_page_offset;
            if offset.is_none() || results.result.is_empty() {
                break;
            }
        }

        Ok(DocumentStatsResponse {
            total_chunks,
            total_files: file_ids.len() as u64,
            total_groups: chunks_by_group.len() as u64,
            chunks_by_group,
        })
    }

    /// Get distinct group keys
    pub async fn get_document_group_keys(&self, user_id: i64) -> Result<Vec<String>> {
        let stats = self.get_document_stats(user_id).await?;
        Ok(stats.chunks_by_group.keys().cloned().collect())
    }
}
```

---

## Route Registration

```rust
// main.rs - Add document routes

use axum::{
    routing::{delete, get, post},
    Router,
};

fn create_router(client: Arc<QdrantClient>) -> Router {
    Router::new()
        // Existing memory routes...
        .route("/memories", post(handlers::upsert_memory))
        .route("/memories/batch", post(handlers::batch_upsert_memories))
        .route("/memories/search", post(handlers::search_memories))
        // ... other memory routes

        // NEW: Document routes
        .route("/documents", post(handlers::upsert_document))
        .route("/documents/batch", post(handlers::batch_upsert_documents))
        .route("/documents/search", post(handlers::search_documents))
        .route("/documents/:point_id", get(handlers::get_document))
        .route("/documents/:point_id", delete(handlers::delete_document))
        .route("/documents/delete-by-file", post(handlers::delete_by_file))
        .route("/documents/delete-by-group", post(handlers::delete_by_group_key))
        .route("/documents/user/:user_id", delete(handlers::delete_all_for_user))
        .route("/documents/update-group-key", post(handlers::update_group_key))
        .route("/documents/stats/:user_id", get(handlers::get_document_stats))
        .route("/documents/groups/:user_id", get(handlers::get_group_keys))

        // Health and capabilities
        .route("/health", get(handlers::health))
        .route("/capabilities", get(handlers::capabilities))

        .with_state(client)
}
```

---

## OpenAPI Documentation

Update the OpenAPI schema:

```rust
// main.rs - Add document schemas to OpenAPI

#[derive(OpenApi)]
#[openapi(
    paths(
        // Existing...
        handlers::upsert_memory,
        handlers::search_memories,
        // NEW
        handlers::upsert_document,
        handlers::batch_upsert_documents,
        handlers::search_documents,
        handlers::get_document,
        handlers::delete_document,
        handlers::delete_by_file,
        handlers::delete_by_group_key,
        handlers::delete_all_for_user,
        handlers::update_group_key,
        handlers::get_document_stats,
        handlers::get_group_keys,
    ),
    components(schemas(
        // Existing...
        MemoryPayload,
        // NEW
        DocumentPayload,
        UpsertDocumentRequest,
        BatchUpsertDocumentsRequest,
        BatchUpsertResponse,
        SearchDocumentsRequest,
        DocumentSearchResult,
        DeleteByFileRequest,
        DeleteByGroupKeyRequest,
        UpdateGroupKeyRequest,
        DocumentStatsResponse,
    )),
    tags(
        (name = "memories", description = "User memory operations"),
        (name = "documents", description = "Document chunk operations"),
    )
)]
struct ApiDoc;
```

---

## Summary of Changes

| File | Change |
|------|--------|
| `config.rs` | Add `documents_collection_name` |
| `models.rs` | Add document-related structs |
| `handlers.rs` | Add 11 new handler functions |
| `qdrant.rs` | Add document operations |
| `main.rs` | Add routes, update OpenAPI |

**Backward compatible** - existing memory endpoints unchanged.
