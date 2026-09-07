/** Espelho dos payloads JSON do `ConnectController` do pacote zapmizer. */

export interface ZapmizerConnection {
  id: number;
  zapmizer_team_id: number | null;
  zapmizer_team_name: string | null;
  phone_number: string | null;
  bot_instance_id: number | null;
  connected_at: string | null;
  webhook_id: number | null;
  is_active: boolean;
  api_token_masked: string | null;
  created_at: string;
  updated_at: string;
  /**
   * Só com `?live=1`: estado da instância no Zapmizer (`connected`,
   * `disconnected`, `qrcode`, `off`, ...) ou um dos estados do pacote
   * (`reauth_required`, `instance_gone`, `zapmizer_unavailable`). `null`
   * quando não há o que consultar.
   */
  state?: string | null;
  /** Só com `?live=1`. */
  is_online?: boolean;
}

/** O que o popup do callback manda por postMessage. */
export type ZapmizerConnectStatus =
  | 'ok'
  | 'denied'
  | 'plan_limit'
  | 'qr_unavailable'
  | 'invalid_state'
  | 'exchange_failed'
  | 'webhook_failed'
  | 'team_already_connected'
  | 'no_connectable';

export interface ZapmizerConnectMessage {
  source: 'zapmizer-connect';
  status: ZapmizerConnectStatus;
  message: string;
}
