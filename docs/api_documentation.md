# WhatsApp Backend API Documentation

This document describes all REST API endpoints and WebSocket server message protocols for the WhatsApp-like Chat Application.

---

## Global Response Formats

All API responses follow the standard JSON response layouts:

### Success (HTTP 200/201)
```json
{
  "status": true,
  "message": "Success message description",
  "data": {}
}
```

### Failure (HTTP 4xx/5xx)
```json
{
  "status": false,
  "message": "Error explanation text",
  "error_code": 401
}
```

---

## 1. Authentication APIs

### Generate OTP
* **URL**: `/api/auth/otp/generate`
* **Method**: `POST`
* **Headers**: `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "mobile_number": "+919876543210"
  }
  ```
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "OTP sent successfully",
    "data": {
      "resend_after_seconds": 60
    }
  }
  ```

### Verify OTP
* **URL**: `/api/auth/otp/verify`
* **Method**: `POST`
* **Headers**: `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "mobile_number": "+919876543210",
    "otp": "123456"
  }
  ```
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "OTP verified successfully",
    "data": {
      "is_new_user": false,
      "user": {
        "id": "c1f7b0f0-c52c-49e3-827d-0db8f4f039ff",
        "mobile_number": "+919876543210",
        "display_name": "Bob",
        "about": "Hey there! I am using WhatsApp.",
        "profile_picture": "uploads/profiles/avatar.webp",
        "online_status": "offline",
        "last_seen": "2026-06-27 12:00:00"
      },
      "tokens": {
        "access_token": "eyJhbGciOi...",
        "refresh_token": "eyJhbGciOi...",
        "expires_in": 3600
      }
    }
  }
  ```

### Refresh Token
* **URL**: `/api/auth/refresh`
* **Method**: `POST`
* **Headers**: `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "refresh_token": "eyJhbGciOi..."
  }
  ```
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Token refreshed successfully",
    "data": {
      "tokens": {
        "access_token": "eyJhbGciOi...",
        "refresh_token": "eyJhbGciOi...",
        "expires_in": 3600
      }
    }
  }
  ```

---

## 2. User & Profile APIs

### Update Profile
* **URL**: `/api/profile/update`
* **Method**: `POST`
* **Headers**: `Authorization: Bearer <access_token>`, `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "display_name": "Alice Cooper",
    "about": "Busy coding!",
    "privacy_last_seen": "contacts"
  }
  ```
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Profile updated successfully",
    "data": {
      "user": {
        "id": "c1f7b0f0-c52c-49e3-827d-0db8f4f039ff",
        "display_name": "Alice Cooper",
        "about": "Busy coding!",
        "privacy_last_seen": "contacts"
      }
    }
  }
  ```

### Register Device Token
* **URL**: `/api/profile/token`
* **Method**: `POST`
* **Headers**: `Authorization: Bearer <access_token>`, `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "device_token": "fcm_device_token_xyz_12345",
    "platform": "android"
  }
  ```
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Device token registered successfully"
  }
  ```

---

## 3. Contact APIs

### Contact Synchronization
* **URL**: `/api/contact/sync`
* **Method**: `POST`
* **Headers**: `Authorization: Bearer <access_token>`, `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "contacts": [
      { "name": "Alice Friend", "phone": "+12345678901" },
      { "name": "Bob Work", "phone": "+19876543210" }
    ]
  }
  ```
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Contacts synchronized successfully",
    "data": {
      "registered_contacts": [
        {
          "id": "uuid-recipient-bob",
          "mobile_number": "+19876543210",
          "display_name": "Bob",
          "about": "Hello!",
          "profile_picture": "uploads/profiles/bob.webp",
          "online_status": "online",
          "local_contact_name": "Bob Work"
        }
      ]
    }
  }
  ```

---

## 4. Chat & Messaging APIs

### Start Chat
* **URL**: `/api/chat/start`
* **Method**: `POST`
* **Headers**: `Authorization: Bearer <access_token>`, `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "receiver_phone": "+19876543210"
  }
  ```
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Chat initialized successfully",
    "data": {
      "conversation": {
        "conversation_id": "33333333-3333-4333-8333-333333333333",
        "unread_count": 0,
        "partner_id": "uuid-recipient-bob",
        "partner_name": "Bob",
        "partner_avatar": "uploads/profiles/bob.webp",
        "partner_status": "online"
      }
    }
  }
  ```

### Get Conversation List
* **URL**: `/api/chat/conversations`
* **Method**: `GET`
* **Headers**: `Authorization: Bearer <access_token>`
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Conversations retrieved successfully",
    "data": {
      "conversations": [
        {
          "conversation_id": "33333333-3333-4333-8333-333333333333",
          "unread_count": 2,
          "partner_id": "uuid-recipient-bob",
          "partner_name": "Bob",
          "partner_avatar": "uploads/profiles/bob.webp",
          "partner_status": "online",
          "last_message": {
            "id": 12,
            "message_uuid": "00000000-0000-4000-8000-000000000001",
            "type": "text",
            "content": "Hey there!",
            "created_at": "2026-06-27 12:00:00"
          }
        }
      ]
    }
  }
  ```

### Get Messages (Paginated)
* **URL**: `/api/chat/messages`
* **Method**: `GET`
* **Headers**: `Authorization: Bearer <access_token>`
* **Query Parameters**:
  * `conversation_id`: string (UUID)
  * `limit`: int (default: 50)
  * `before_id`: int (optional, message cursor)
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Messages retrieved successfully",
    "data": {
      "messages": [
        {
          "id": 11,
          "message_uuid": "...",
          "conversation_id": "...",
          "sender_id": "...",
          "type": "text",
          "content": "Hi Bob",
          "delivery_status": "seen",
          "reactions": [],
          "created_at": "2026-06-27 11:59:00"
        }
      ]
    }
  }
  ```

### Search Messages
* **URL**: `/api/chat/search`
* **Method**: `GET`
* **Headers**: `Authorization: Bearer <access_token>`
* **Query Parameters**:
  * `query`: string (search term)
  * `type`: string (optional: `image`, `video`, `voice`, etc.)
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Search results retrieved successfully",
    "data": {
      "results": []
    }
  }
  ```

### Incremental Sync (`/api/chat/sync`)
* **URL**: `/api/chat/sync`
* **Method**: `POST`
* **Headers**: `Authorization: Bearer <access_token>`, `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "sync_items": [
      {
        "conversation_id": "33333333-3333-4333-8333-333333333333",
        "last_message_id": 10
      }
    ],
    "last_sync_time": "2026-06-27 12:00:00.000000"
  }
  ```
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Sync completed successfully",
    "data": {
      "sync_items": {
        "33333333-3333-4333-8333-333333333333": {
          "new_messages": [
            {
              "id": 11,
              "message_uuid": "...",
              "type": "text",
              "content": "Hey!",
              "created_at": "2026-06-27 12:01:00.000000"
            }
          ],
          "latest_message_id": 11
        }
      },
      "deletions": [
        {
          "message_id": 9,
          "message_uuid": "deleted-msg-uuid",
          "delete_type": "everyone"
        }
      ],
      "status_updates": [
        {
          "message_id": 8,
          "message_uuid": "msg-8-uuid",
          "status": "seen"
        }
      ],
      "reactions": [],
      "server_time": "2026-06-27 12:05:00.000000"
    }
  }
  ```

### Send Message (REST Fallback)
* **URL**: `/api/message/send`
* **Method**: `POST`
* **Headers**: `Authorization: Bearer <access_token>`, `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "message_uuid": "client-generated-uuid-1",
    "conversation_id": "33333333-3333-4333-8333-333333333333",
    "type": "text",
    "content": "Hello Bob",
    "reply_to_message_id": null
  }
  ```

### Add Reaction
* **URL**: `/api/message/react`
* **Method**: `POST`
* **Headers**: `Authorization: Bearer <access_token>`, `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "message_uuid": "message-uuid",
    "reaction": "❤️"
  }
  ```

### Delete Message
* **URL**: `/api/message/delete`
* **Method**: `POST`
* **Headers**: `Authorization: Bearer <access_token>`, `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "message_uuid": "message-uuid",
    "delete_type": "everyone" // "me" or "everyone"
  }
  ```

---

## 5. Media APIs

### Upload Media
* **URL**: `/api/media/upload`
* **Method**: `POST`
* **Headers**: `Authorization: Bearer <access_token>`
* **Request Body (Multipart Form Data)**:
  * `file`: (Binary File)
  * `type`: string (image, video, voice, document, sticker, profile)
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "File uploaded successfully",
    "data": {
      "media_id": "file-uuid-path",
      "file_path": "uploads/media/file-uuid.webp",
      "file_size": 24050,
      "thumbnail_path": "uploads/media/file-uuid_thumb.webp",
      "width": 1200,
      "height": 900,
      "waveform": null
    }
  }
  ```

### Sticker Packs
* **URL**: `/api/stickers/packs`
* **Method**: `GET`
* **Headers**: `Authorization: Bearer <access_token>`
* **Success Response (200)**:
  ```json
  {
    "status": true,
    "message": "Sticker packs retrieved successfully",
    "data": {
      "sticker_packs": [
        {
          "pack_id": "pack_01",
          "name": "Cuppy The Cupcake",
          "publisher": "WhatsApp",
          "is_animated": false,
          "thumbnail": "uploads/stickers/cuppy/thumb.webp",
          "stickers": [
            { "id": "cuppy_01", "url": "uploads/stickers/cuppy/cuppy1.webp" }
          ]
        }
      ]
    }
  }
  ```

---

## 6. WebSocket Events (Port 8080)

All WebSocket payloads must be stringified JSON and specify an `event` parameter.

### Connection
Clients must connect using their JWT access token inside query parameters:
`ws://127.0.0.1:8080?token=eyJhbGciOi...`

---

### Outgoing Events (Client to Server)

#### Typing indicator
```json
{
  "event": "typing",
  "conversation_id": "33333333-3333-4333-8333-333333333333"
}
```

#### Stop Typing
```json
{
  "event": "stop_typing",
  "conversation_id": "33333333-3333-4333-8333-333333333333"
}
```

#### Send Message
```json
{
  "event": "message",
  "message_uuid": "client-uuid-123",
  "conversation_id": "33333333-3333-4333-8333-333333333333",
  "type": "text",
  "content": "Hello via WebSocket!"
}
```

#### Update Status Tick (Delivered / Seen)
```json
{
  "event": "status_update",
  "message_uuid": "client-uuid-123",
  "status": "seen"
}
```

#### Delete Message
```json
{
  "event": "delete",
  "message_uuid": "client-uuid-123",
  "delete_type": "everyone"
}
```

---

### Incoming Events (Server to Client)

#### Message Acknowledgement (Sent confirmation to sender)
```json
{
  "event": "message_ack",
  "message_uuid": "client-uuid-123",
  "id": 45,
  "status": "sent"
}
```

#### New Message Broadcast (To recipient)
```json
{
  "event": "message",
  "id": 45,
  "message_uuid": "client-uuid-123",
  "conversation_id": "33333333-3333-4333-8333-333333333333",
  "sender_id": "uuid-sender",
  "type": "text",
  "content": "Hello via WebSocket!",
  "created_at": "2026-06-27 12:10:00.000000"
}
```

#### Status Tick Updates (To sender)
```json
{
  "event": "status_update",
  "message_uuid": "client-uuid-123",
  "status": "seen",
  "user_id": "uuid-recipient"
}
```

#### Presence Indicator Updates
```json
{
  "event": "presence_update",
  "user_id": "uuid-user-a",
  "status": "offline",
  "last_seen": "2026-06-27 12:15:00"
}
```
