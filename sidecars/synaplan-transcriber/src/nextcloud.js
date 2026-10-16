function encodePath(folder, filename) {
  const folderPath = `/${folder}`.replace(/\/{2,}/g, '/').replace(/\/$/, '')
  return `${folderPath}/${filename}`
    .split('/')
    .map((part) => encodeURIComponent(part))
    .join('/')
}

/**
 * Write one Markdown file with a Nextcloud app password.
 * Returns where it landed, or throws with the HTTP status.
 */
export async function putNextcloudMarkdown({
  baseUrl,
  user,
  password,
  folder,
  filename,
  markdown,
  fetchImpl = fetch,
}) {
  const path = encodePath(folder, filename)
  const url = `${baseUrl.replace(/\/$/, '')}/remote.php/dav/files/${encodeURIComponent(user)}${path}`
  const response = await fetchImpl(url, {
    method: 'PUT',
    headers: {
      Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
      'Content-Type': 'text/markdown; charset=utf-8',
    },
    body: markdown,
  })
  if (response.status !== 200 && response.status !== 201 && response.status !== 204) {
    const error = new Error(`Files refused the notes (${response.status})`)
    error.status = response.status
    throw error
  }
  return { path: `${folder.replace(/\/$/, '')}/${filename}` }
}
