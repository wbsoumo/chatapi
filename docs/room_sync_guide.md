# Android Room DB Local-First Sync Guide

This document outlines the synchronization strategy between the Android Room Database (SQLite) and the PHP Backend API.

---

## 1. Local Database Schema Design (Android Room)

To implement a local-first messaging experience like WhatsApp, the Android client must maintain a set of entities matching the server:

### Entities & Relationships

1. **UserEntity**
   * `id`: String (UUID, Primary Key)
   * `mobile_number`: String
   * `display_name`: String
   * `profile_picture`: String
   * `about`: String
   * `online_status`: String
   * `last_seen`: Long (Timestamp)

2. **ConversationEntity**
   * `id`: String (UUID, Primary Key)
   * `partner_id`: String (Foreign Key to UserEntity)
   * `last_synced_message_id`: Int
   * `unread_count`: Int
   * `last_message_content`: String
   * `last_message_time`: Long

3. **MessageEntity**
   * `id`: Int (Server-provided ID, Primary Key)
   * `message_uuid`: String (Client-generated UUID, Unique index)
   * `conversation_id`: String (Foreign Key to ConversationEntity)
   * `sender_id`: String (Foreign Key to UserEntity)
   * `type`: String (text, image, video, voice, doc, gif, sticker)
   * `content`: String (Null for media messages)
   * `reply_to_message_id`: Int
   * `forwarded`: Boolean
   * `delivery_status`: String (sending, sent, delivered, seen)
   * `created_at`: Long (Epoch milliseconds)

4. **MessageMediaEntity**
   * `message_id`: Int (Primary Key, Foreign Key to MessageEntity)
   * `file_path`: String (Local path on device)
   * `server_url`: String (Remote URL path)
   * `file_size`: Long
   * `mime_type`: String
   * `duration`: Int (For voice/video)
   * `waveform`: String (JSON float array for voice)
   * `thumbnail_path`: String

5. **OfflineQueueEntity (Sync Queue)**
   * `id`: Int (Auto-increment, Primary Key)
   * `action_type`: String (send_message, update_status, add_reaction, delete_message)
   * `payload`: String (JSON details)
   * `created_at`: Long

---

## 2. Incremental Sync Workflow

The synchronization uses a combination of **REST API Pull** (on start or network recovery) and **WebSocket Push** (while active).

```
   Android Room DB                Network                PHP Sync API
        |                            |                        |
        |---- Fetch Last Msg ID ---->|                        |
        |     & Last Sync Timestamp  |                        |
        |                            |----- POST /chat/sync ->|
        |                            |      (Items & Timestamp)|
        |                            |<---- Return Updates ---|
        |                            |      (New, Deleted,    |
        |<--- Update Room DB --------|      Reactions, Ticks) |
```

### Steps for Client Pull:
1. **Prepare Request**: Query the local Room DB to build a list of all active conversations and their highest local `message_id`.
   ```json
   {
     "sync_items": [
       { "conversation_id": "uuid-a", "last_message_id": 105 },
       { "conversation_id": "uuid-b", "last_message_id": 1450 }
     ],
     "last_sync_time": "2026-06-27 12:00:00.000000"
   }
   ```
2. **Execute Request**: Post to `/api/chat/sync`.
3. **Parse & Save Response**:
   * **New Messages**: Loop through `sync_items`. For each conversation, insert new records into the Room database.
   * **Deletions**: Delete any corresponding message matching the `message_uuid` in the deletions payload.
   * **Status Ticks**: Update `delivery_status` to `delivered` or `seen` for the matching UUIDs.
   * **Reactions**: Add reactions to local message entities.
4. **Update Metadata**: Save the returned `server_time` locally as the new `last_sync_time` for the next cycle.

---

## 3. Offline Support & Message Sending Flow

When a user types a message and clicks Send:

1. **Immediate Local Save**:
   * Create a new `MessageEntity` with `delivery_status = 'sending'`, using a client-side generated UUID.
   * Save it in the local Room DB. The chat UI updates instantly.
2. **Offline Queueing**:
   * If there is no internet connection, insert a job in `OfflineQueueEntity` containing the message payload.
3. **Transmission (REST / WebSocket)**:
   * Attempt to transmit via WebSocket if active, or POST to `/api/message/send` if using REST.
4. **Server Confirmation**:
   * The server returns a status of `sent` along with the autoincremented `id`.
   * Update the local `MessageEntity` matching the client-side UUID: change `delivery_status` to `sent` and assign the server-provided `id`.

---

## 4. WebSocket Event Handlers (Real-time Sync)

While the app is open and connected to `ws://host:port?token=JWT`:

* **Incoming `message` event**:
  1. Insert into local `MessageEntity` immediately as `delivered`.
  2. Send a `status_update` event back to the server: `{"event": "status_update", "message_uuid": "...", "status": "delivered"}`.
* **Incoming `status_update` event**:
  1. Update `delivery_status` of the matching message to `delivered` or `seen` (double tick / blue tick).
* **Incoming `typing` event**:
  1. Trigger temporary UI indicator "Typing..." on the chat toolbar. Remove after 3 seconds or on receiving a `stop_typing` event.
