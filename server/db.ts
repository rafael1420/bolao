import mysql, { type ResultSetHeader } from 'mysql2/promise'
import { env } from './env.ts'

// Datas voltam como texto ("2026-10-29 16:00:00"); a conversão fica em time.ts.
export const pool = mysql.createPool({
  ...env.db,
  charset: 'utf8mb4',
  connectionLimit: 8,
  dateStrings: true,
  decimalNumbers: true,
  supportBigNumbers: true,
  enableKeepAlive: true,
})

type Param = string | number | boolean | null | Date
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type Row = Record<string, any>

/** SELECT: linhas. */
export async function q<T = Row>(sql: string, params: Param[] = []): Promise<T[]> {
  const [rows] = await pool.query(sql, params)
  return rows as T[]
}

/** SELECT de uma linha (ou null). */
export async function one<T = Row>(sql: string, params: Param[] = []): Promise<T | null> {
  return (await q<T>(sql, params))[0] ?? null
}

/** INSERT/UPDATE/DELETE. */
export async function exec(sql: string, params: Param[] = []): Promise<ResultSetHeader> {
  const [res] = await pool.query(sql, params)
  return res as ResultSetHeader
}

/** "?, ?, ?" para uma lista. */
export const ph = (items: unknown[]) => items.map(() => '?').join(',')

/** Transação com uma conexão só. */
export async function tx<T>(fn: (c: { exec: typeof exec; q: typeof q }) => Promise<T>): Promise<T> {
  const conn = await pool.getConnection()
  try {
    await conn.beginTransaction()
    const api = {
      exec: async (sql: string, params: Param[] = []) => (await conn.query(sql, params))[0] as ResultSetHeader,
      q: async <R = Row,>(sql: string, params: Param[] = []) => (await conn.query(sql, params))[0] as R[],
    }
    const out = await fn(api as never)
    await conn.commit()
    return out
  } catch (e) {
    await conn.rollback()
    throw e
  } finally {
    conn.release()
  }
}

/** Trecho de SQL que deixa de fora jogos adiados ou cancelados. */
export const notVoid = (alias = 'm') => `(${alias}.status IS NULL OR ${alias}.status <> 'void')`
