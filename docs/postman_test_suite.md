# WhatsApp API Postman Testing Guide

Use this guide to test all REST API endpoints step-by-step in Postman.

* **Base URL**: `http://coundownhub.com/api` (or `http://coundownhub.com/public/api` depending on your cPanel folder name)
* **Auth Requirement**: Endpoints marked with **[Auth Required]** require you to go to the **Headers** tab in Postman and add:
  * Key: `Authorization`
  * Value: `Bearer YOUR_ACCESS_TOKEN` (copy this from the *Verify OTP* response)

---

## 1. Authentication Flow

### Step 1: Generate OTP
* **Method**: `POST`
* **URL**: `{{BaseURL}}/auth/otp/generate`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "mobile_number": "+919876543210"
  }
  ```

### Step 2: Verify OTP
* **Method**: `POST`
* **URL**: `{{BaseURL}}/auth/otp/verify`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "mobile_number": "+919876543210",
      "otp": "123456"
  }
  ```
  *(Note: Paste the 6-digit OTP code sent to your WhatsApp or found inside the cPanel log file at `logs/app.log`)*

### Step 3: Refresh Access Token
* **Method**: `POST`
* **URL**: `{{BaseURL}}/auth/refresh`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "refresh_token": "YOUR_REFRESH_TOKEN_HERE"
  }
  ```

---

## 2. Profile Management [Auth Required]

### Update Profile Details
* **Method**: `POST`
* **URL**: `{{BaseURL}}/profile/update`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "display_name": "Soumojit Saha",
      "about": "Available",
      "privacy_last_seen": "contacts"
  }
  ```

### Register Device Token (FCM)
* **Method**: `POST`
* **URL**: `{{BaseURL}}/profile/token`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "device_token": "fcm_device_token_sample_12345",
      "platform": "android"
  }
  ```

---

## 3. Contacts Synchronization [Auth Required]

### Synchronize Phone Contacts
* **Method**: `POST`
* **URL**: `{{BaseURL}}/contact/sync`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "contacts": [
          { "name": "Jane Friend", "phone": "+918888888888" },
          { "name": "Work Partner", "phone": "+19876543210" }
      ]
  }
  ```

---

## 4. Conversations & Messaging [Auth Required]

### Start Conversation
* **Method**: `POST`
* **URL**: `{{BaseURL}}/chat/start`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "receiver_phone": "+19876543210"
  }
  ```

### Get Conversation List
* **Method**: `GET`
* **URL**: `{{BaseURL}}/chat/conversations`

### Get Chat Messages (Paginated)
* **Method**: `GET`
* **URL**: `{{BaseURL}}/chat/messages?conversation_id=YOUR_CONVERSATION_UUID&limit=20`

### Search Messages
* **Method**: `GET`
* **URL**: `{{BaseURL}}/chat/search?query=hello&type=text`

### Send Message
* **Method**: `POST`
* **URL**: `{{BaseURL}}/message/send`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "message_uuid": "e8d64119-9430-4e33-87bb-786d526e03ea",
      "conversation_id": "YOUR_CONVERSATION_UUID",
      "type": "text",
      "content": "Hello Bob, how are you?",
      "reply_to_message_id": null
  }
  ```

### Update Message Status
* **Method**: `POST`
* **URL**: `{{BaseURL}}/message/status`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "message_uuid": "e8d64119-9430-4e33-87bb-786d526e03ea",
      "status": "seen"
  }
  ```

### Add Reaction
* **Method**: `POST`
* **URL**: `{{BaseURL}}/message/react`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "message_uuid": "e8d64119-9430-4e33-87bb-786d526e03ea",
      "reaction": "❤️"
  }
  ```

### Delete Message
* **Method**: `POST`
* **URL**: `{{BaseURL}}/message/delete`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "message_uuid": "e8d64119-9430-4e33-87bb-786d526e03ea",
      "delete_type": "everyone"
  }
  ```

### Delete Conversation
* **Method**: `POST`
* **URL**: `{{BaseURL}}/conversation/delete`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "conversation_id": "YOUR_CONVERSATION_UUID"
  }
  ```

---

## 5. Incremental Synchronization [Auth Required]

### Synchronize Changes (New messages, edits, reactions, ticks)
* **Method**: `POST`
* **URL**: `{{BaseURL}}/chat/sync`
* **Headers**: `Content-Type: application/json`
* **Body** (raw JSON):
  ```json
  {
      "sync_items": [
          {
              "conversation_id": "YOUR_CONVERSATION_UUID",
              "last_message_id": 0
          }
      ],
      "last_sync_time": "2026-06-27 12:00:00.000000"
  }
  ```

---

## 6. Media & Assets [Auth Required]

### Upload File (Image, Video, Voice, Document)
* **Method**: `POST`
* **URL**: `{{BaseURL}}/media/upload`
* **Body** (form-data):
  * Key: `file` | Type: `File` | Value: *(Select any local image/file)*
  * Key: `type` | Type: `Text` | Value: `image` *(Values: `image`, `video`, `voice`, `document`, `sticker`)*

### Search GIFs (Tenor API Integration)
* **Method**: `GET`
* **URL**: `{{BaseURL}}/gif/search?query=excited&limit=5`

### Trending GIFs (Tenor API Integration)
* **Method**: `GET`
* **URL**: `{{BaseURL}}/gif/trending?limit=5`

### Sticker Packs
* **Method**: `GET`
* **URL**: `{{BaseURL}}/stickers/packs`
