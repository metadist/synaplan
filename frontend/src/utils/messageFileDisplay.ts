/** Glyph for an attachment badge in a chat message, by file extension. */
export const getFileIcon = (fileType: string): string => {
  const type = fileType.toLowerCase()
  if (['pdf'].includes(type)) return 'mdi:file-pdf-box'
  if (['doc', 'docx'].includes(type)) return 'mdi:file-word-box'
  if (['xls', 'xlsx'].includes(type)) return 'mdi:file-excel-box'
  if (['ppt', 'pptx'].includes(type)) return 'mdi:file-powerpoint-box'
  if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(type)) return 'mdi:file-image'
  if (['mp3', 'wav', 'ogg', 'm4a', 'opus'].includes(type)) return 'mdi:file-music'
  if (['mp4', 'avi', 'mov', 'webm'].includes(type)) return 'mdi:file-video'
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(type)) return 'mdi:folder-zip'
  if (['txt', 'md'].includes(type)) return 'mdi:file-document-outline'
  return 'mdi:file-outline'
}

/** Attachment size as shown on the badge: one decimal from KB up. */
export const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB'
}
