import { readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'
import { parse as parseSfc } from '@vue/compiler-sfc'
import ts from 'typescript'

/**
 * The two size numbers of the frontend sources: lines per file and lines per
 * function, the frontend twin of the backend's `SourceMetrics`. `.ts` files
 * and the `<script>` blocks of `.vue` files (split with `@vue/compiler-sfc`)
 * are read with the TypeScript compiler API. A function counts from its first
 * to its last line; it is keyed by its enclosing functions, an anonymous one
 * by the property or call it is passed to, and a repeat gets `#2`, `#3`.
 */

export const MAX_FILE_LINES = 800
export const MAX_FUNCTION_LINES = 100

const SOURCE_EXTENSIONS = ['.ts', '.vue']
const EXCLUDED_DIRECTORIES = ['src/generated']
const ANONYMOUS = '<anonymous>'

export interface SourceMetrics {
  lines: number
  /** "outer.inner" => lines */
  functionLines: Record<string, number>
}

export interface SizeOffenders {
  scanned: number
  /** "src/path/File.vue" => lines */
  fileLines: Record<string, number>
  /** "src/path/File.vue::outer.inner" => lines */
  functionLines: Record<string, number>
}

export function lineCount(code: string): number {
  if (code === '') {
    return 0
  }
  return code.split('\n').length - (code.endsWith('\n') ? 1 : 0)
}

export function measureSource(code: string, fileName: string): SourceMetrics {
  const functionLines: Record<string, number> = {}
  const taken = new Set<string>()
  for (const block of fileName.endsWith('.vue') ? scriptBlocks(code, fileName) : [code]) {
    const source = ts.createSourceFile(
      fileName,
      block,
      ts.ScriptTarget.Latest,
      true,
      ts.ScriptKind.TS
    )
    collectFunctions(source, source, [], functionLines, taken)
  }
  return { lines: lineCount(code), functionLines }
}

export function scanOffenders(frontendRoot: string): SizeOffenders {
  const offenders: SizeOffenders = { scanned: 0, fileLines: {}, functionLines: {} }
  for (const path of sourceFiles(join(frontendRoot, 'src'))) {
    const file = relative(frontendRoot, path).split(sep).join('/')
    const metrics = measureSource(readFileSync(path, 'utf8'), file)
    offenders.scanned++
    if (metrics.lines > MAX_FILE_LINES) {
      offenders.fileLines[file] = metrics.lines
    }
    for (const [name, lines] of Object.entries(metrics.functionLines)) {
      if (lines > MAX_FUNCTION_LINES) {
        offenders.functionLines[`${file}::${name}`] = lines
      }
    }
  }
  return offenders
}

function scriptBlocks(code: string, fileName: string): string[] {
  const { descriptor } = parseSfc(code, { filename: fileName })
  return [descriptor.script, descriptor.scriptSetup]
    .filter((block) => block !== null)
    .sort((left, right) => left.loc.start.offset - right.loc.start.offset)
    .map((block) => block.content)
}

function* sourceFiles(directory: string): Generator<string> {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) {
      if (!EXCLUDED_DIRECTORIES.some((excluded) => path.split(sep).join('/').endsWith(excluded))) {
        yield* sourceFiles(path)
      }
    } else if (SOURCE_EXTENSIONS.some((extension) => entry.name.endsWith(extension))) {
      yield path
    }
  }
}

function collectFunctions(
  node: ts.Node,
  source: ts.SourceFile,
  scope: string[],
  out: Record<string, number>,
  taken: Set<string>
): void {
  ts.forEachChild(node, (child) => {
    if (!isMeasured(child)) {
      collectFunctions(child, source, scope, out, taken)
      return
    }
    const segment = uniqueSegment(scope, functionName(child), taken)
    const first = source.getLineAndCharacterOfPosition(child.getStart(source)).line
    const last = source.getLineAndCharacterOfPosition(child.getEnd()).line
    out[[...scope, segment].join('.')] = last - first + 1
    collectFunctions(child, source, [...scope, segment], out, taken)
  })
}

function isMeasured(node: ts.Node): node is ts.FunctionLikeDeclaration {
  return (
    (ts.isFunctionDeclaration(node) ||
      ts.isFunctionExpression(node) ||
      ts.isArrowFunction(node) ||
      ts.isMethodDeclaration(node) ||
      ts.isConstructorDeclaration(node) ||
      ts.isGetAccessorDeclaration(node) ||
      ts.isSetAccessorDeclaration(node)) &&
    node.body !== undefined
  )
}

function functionName(node: ts.FunctionLikeDeclaration): string {
  if (ts.isConstructorDeclaration(node)) {
    return 'constructor'
  }
  if (node.name !== undefined) {
    return propertyName(node.name)
  }
  return nameFromContext(node)
}

function nameFromContext(node: ts.Node): string {
  let child: ts.Node = node
  let parent = node.parent
  while (
    ts.isParenthesizedExpression(parent) ||
    ts.isAsExpression(parent) ||
    ts.isSatisfiesExpression(parent)
  ) {
    child = parent
    parent = parent.parent
  }
  if (
    (ts.isVariableDeclaration(parent) || ts.isPropertyDeclaration(parent)) &&
    parent.initializer === child
  ) {
    return propertyName(parent.name)
  }
  if (ts.isPropertyAssignment(parent)) {
    return propertyName(parent.name)
  }
  if (ts.isBinaryExpression(parent) && parent.right === child) {
    return expressionName(parent.left)
  }
  if (
    (ts.isCallExpression(parent) || ts.isNewExpression(parent)) &&
    parent.arguments?.includes(child as ts.Expression)
  ) {
    return assignedName(parent) ?? expressionName(parent.expression)
  }
  if (ts.isExportAssignment(parent)) {
    return 'default'
  }
  return ANONYMOUS
}

/** `const useChatsStore = defineStore('chats', () => …)` names the callback `useChatsStore`. */
function assignedName(call: ts.CallExpression | ts.NewExpression): string | null {
  const holder = call.parent
  if (
    ts.isVariableDeclaration(holder) &&
    holder.initializer === call &&
    ts.isIdentifier(holder.name)
  ) {
    return holder.name.text
  }
  return null
}

function propertyName(name: ts.PropertyName | ts.BindingName): string {
  if (
    ts.isIdentifier(name) ||
    ts.isPrivateIdentifier(name) ||
    ts.isStringLiteral(name) ||
    ts.isNumericLiteral(name)
  ) {
    return name.text
  }
  return ANONYMOUS
}

function expressionName(expression: ts.Expression): string {
  if (ts.isIdentifier(expression)) {
    return expression.text
  }
  if (ts.isPropertyAccessExpression(expression)) {
    return expression.name.text
  }
  if (
    ts.isElementAccessExpression(expression) &&
    ts.isStringLiteral(expression.argumentExpression)
  ) {
    return expression.argumentExpression.text
  }
  return ANONYMOUS
}

function uniqueSegment(scope: string[], name: string, taken: Set<string>): string {
  let segment = name
  for (let repeat = 2; taken.has([...scope, segment].join('.')); repeat++) {
    segment = `${name}#${repeat}`
  }
  taken.add([...scope, segment].join('.'))
  return segment
}
