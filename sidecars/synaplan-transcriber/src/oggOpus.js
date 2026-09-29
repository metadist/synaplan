/**
 * Wrap raw Opus packets (the payload Jitsi sends) in an Ogg Opus stream.
 * Synaplan's speech-to-text path accepts Ogg. Audio is not written to disk.
 */

const CRC_POLY = 0x04c11db7

const CRC_TABLE = (() => {
  const table = new Uint32Array(256)
  for (let i = 0; i < 256; i += 1) {
    let r = i * 0x1000000
    for (let bit = 0; bit < 8; bit += 1) {
      if (r & 0x80000000) {
        r = (Math.imul(r, 2) ^ CRC_POLY) >>> 0
      } else {
        r = Math.imul(r, 2) >>> 0
      }
    }
    table[i] = r
  }
  return table
})()

/** Samples at 48 kHz for each Opus TOC configuration (RFC 6716). */
const FRAME_SAMPLES = [
  480, 960, 1920, 2880,
  480, 960, 1920, 2880,
  480, 960, 1920, 2880,
  480, 960,
  480, 960,
  120, 240, 480, 960,
  120, 240, 480, 960,
  120, 240, 480, 960,
  120, 240, 480, 960,
]

export function oggCrc32(buffer) {
  let crc = 0
  for (let i = 0; i < buffer.length; i += 1) {
    const index = ((crc >>> 24) ^ buffer[i]) & 0xff
    crc = ((crc << 8) ^ CRC_TABLE[index]) >>> 0
  }
  return crc >>> 0
}

export function opusPacketSamples(packet) {
  if (!packet || packet.length < 1) {
    return 960
  }
  const toc = packet[0]
  const frame = FRAME_SAMPLES[toc >> 3] ?? 960
  const code = toc & 0x3
  if (code === 0) {
    return frame
  }
  if (code === 1 || code === 2) {
    return frame * 2
  }
  if (packet.length < 2) {
    return frame
  }
  const count = packet[1] & 0x3f
  return frame * Math.max(1, count)
}

function opusHead(channels, inputRate, preSkip) {
  const packet = Buffer.alloc(19)
  packet.write('OpusHead')
  packet[8] = 1
  packet[9] = channels
  packet.writeUInt16LE(preSkip, 10)
  packet.writeUInt32LE(inputRate, 12)
  packet.writeInt16LE(0, 16)
  packet[18] = 0
  return packet
}

function opusTags(vendor) {
  const name = Buffer.from(vendor)
  const packet = Buffer.alloc(8 + 4 + name.length + 4)
  packet.write('OpusTags')
  packet.writeUInt32LE(name.length, 8)
  name.copy(packet, 12)
  packet.writeUInt32LE(0, 12 + name.length)
  return packet
}

function lace(packets) {
  const segments = []
  const chunks = []
  for (const packet of packets) {
    let offset = 0
    if (packet.length === 0) {
      segments.push(0)
      continue
    }
    while (offset < packet.length) {
      const size = Math.min(255, packet.length - offset)
      segments.push(size)
      chunks.push(packet.subarray(offset, offset + size))
      offset += size
    }
    if (packet.length % 255 === 0) {
      segments.push(0)
    }
  }
  return { segments, chunks }
}

function oggPage({ headerType, granule, serial, sequence, packets }) {
  const { segments, chunks } = lace(packets)
  if (segments.length > 255) {
    throw new Error('Ogg page has more than 255 segments')
  }
  const header = Buffer.alloc(27 + segments.length)
  header.write('OggS')
  header[4] = 0
  header[5] = headerType
  header.writeBigUInt64LE(BigInt(granule), 6)
  header.writeUInt32LE(serial >>> 0, 14)
  header.writeUInt32LE(sequence >>> 0, 18)
  header.writeUInt32LE(0, 22)
  header[26] = segments.length
  for (let i = 0; i < segments.length; i += 1) {
    header[27 + i] = segments[i]
  }
  const body = Buffer.concat(chunks)
  const page = Buffer.concat([header, body])
  page.writeUInt32LE(oggCrc32(page), 22)
  return page
}

/**
 * @param {Buffer[]} packets raw Opus packets
 * @param {{ serial?: number, preSkip?: number, vendor?: string }} [options]
 * @returns {Buffer}
 */
export function muxOpusPackets(packets, options = {}) {
  if (!packets.length) {
    throw new Error('No Opus packets to mux')
  }
  const serial = options.serial ?? 0x53594e31
  const preSkip = options.preSkip ?? 384
  const vendor = options.vendor ?? 'synaplan-transcriber'
  const pages = [
    oggPage({
      headerType: 0x02,
      granule: 0,
      serial,
      sequence: 0,
      packets: [opusHead(1, 48000, preSkip)],
    }),
    oggPage({
      headerType: 0x00,
      granule: 0,
      serial,
      sequence: 1,
      packets: [opusTags(vendor)],
    }),
  ]

  let granule = 0
  let sequence = 2
  let batch = []
  let batchSegments = 0

  const flush = (end) => {
    if (!batch.length) {
      return
    }
    pages.push(oggPage({
      headerType: end ? 0x04 : 0x00,
      granule,
      serial,
      sequence,
      packets: batch,
    }))
    sequence += 1
    batch = []
    batchSegments = 0
  }

  packets.forEach((packet, index) => {
    const segments = Math.ceil(packet.length / 255) + (packet.length % 255 === 0 ? 1 : 0)
    if (batchSegments + segments > 255) {
      flush(false)
    }
    granule += opusPacketSamples(packet)
    batch.push(packet)
    batchSegments += segments
    if (index === packets.length - 1) {
      flush(true)
    }
  })

  return Buffer.concat(pages)
}
