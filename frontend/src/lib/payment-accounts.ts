import { api } from "@/lib/auth";
import type { PaymentAccountInfo } from "@/lib/portal-types";

/** Rekening/VA tujuan pembayaran manual yang aktif. Gagal diambil → daftar kosong (portal tetap tampil teks umum). */
export async function getPaymentAccounts(token: string): Promise<PaymentAccountInfo[]> {
  try {
    return (await api<{ data: PaymentAccountInfo[] }>("/me/payment-accounts", { token })).data;
  } catch {
    return [];
  }
}
