export interface FileItem {
  id: number
  name: string
  direction: 'IN' | 'OUT'
  group: string | null
  details: string
  uploaded: string
  type: string
}

export interface FileGroup {
  name: string
  count: number
}

export const mockFiles: FileItem[] = [
  {
    id: 1413,
    name: 'google_video_1756830896_68b71cb0bbcac...mp4',
    direction: 'OUT',
    group: null,
    details: 'Instances:[{"prompt":"generate on video van e',
    uploaded: '20250902168456',
    type: 'video',
  },
  {
    id: 1834,
    name: 'screenshot_2025_08_18_135911.png',
    direction: 'IN',
    group: 'DEFAULT',
    details: 'The image I see is completely blank and white. The',
    uploaded: '20250905123611',
    type: 'image',
  },
  {
    id: 2129,
    name: 'img_7656.jpg',
    direction: 'IN',
    group: 'DEFAULT',
    details: 'The image depicts a whiteboard with a list of term',
    uploaded: '20250909135528',
    type: 'image',
  },
  {
    id: 2133,
    name: 'secryptor_pro_forma_forecast.xlsx',
    direction: 'IN',
    group: null,
    details: '',
    uploaded: '20250909154109',
    type: 'document',
  },
  {
    id: 2139,
    name: 'secryptor_pro_forma_forecast.xlsx',
    direction: 'IN',
    group: null,
    details: '',
    uploaded: '20250909154140',
    type: 'document',
  },
  {
    id: 2145,
    name: 'secryptor_pro_forma_forecast.xlsx',
    direction: 'OUT',
    group: null,
    details: '',
    uploaded: '20250909154150',
    type: 'document',
  },
  {
    id: 2475,
    name: 'oai_1757969809_2472.png',
    direction: 'OUT',
    group: null,
    details: 'OK: OpenAI Image',
    uploaded: '20250915205649',
    type: 'image',
  },
  {
    id: 2517,
    name: 'wa_1758031761.mp3',
    direction: 'OUT',
    group: null,
    details: '',
    uploaded: '20250916140921',
    type: 'audio',
  },
  {
    id: 2617,
    name: '24377_cert2customer.pdf',
    direction: 'IN',
    group: 'DEFAULT',
    details: 'uber die Besitzubertragung eines Riesling-Rebsto',
    uploaded: '20250919172848',
    type: 'document',
  },
  {
    id: 2623,
    name: '24377_cert2customer.pdf',
    direction: 'IN',
    group: 'DEFAULT',
    details: 'uber die Besitzubertragung eines Riesling-Rebsto',
    uploaded: '20250919173141',
    type: 'document',
  },
]

export const mockFileGroups: FileGroup[] = [
  { name: 'DEFAULT', count: 15 },
  { name: 'Project A', count: 8 },
  { name: 'Marketing', count: 12 },
  { name: 'Research', count: 5 },
]

export const supportedFileTypes = ['PDF', 'DOCX', 'TXT', 'JPG', 'PNG', 'MP3', 'MP4', 'XLSX', 'CSV']
