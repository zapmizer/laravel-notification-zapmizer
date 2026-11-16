/** Espelho dos payloads JSON do `ConnectController` e do `ReconnectController` do pacote zapmizer. */

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
  /**
   * `false`: o número envia, mas não recebe — o connect não trouxe o secret do
   * webhook. Opcional: uma subclasse antiga do model pode não mandar.
   */
  has_webhook_secret?: boolean;
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
  | 'subscription_required'
  | 'invalid_state'
  | 'exchange_failed'
  | 'rate_limited'
  | 'webhook_failed'
  | 'team_already_connected'
  | 'no_connectable';

export interface ZapmizerConnectMessage {
  source: 'zapmizer-connect';
  status: ZapmizerConnectStatus;
  message: string;
}

/** O que o popup do callback do reconnect manda por postMessage. */
export type ZapmizerReconnectStatus =
  | 'reconnected'
  | 'denied'
  | 'plan_limit'
  | 'subscription_required'
  | 'invalid_state'
  | 'failed'
  | 'no_connectable';

export interface ZapmizerReconnectMessage {
  source: 'zapmizer-reconnect';
  status: ZapmizerReconnectStatus;
  message: string;
}

/** O que `POST zapmizer.connect.reconnect` responde em 200. */
export type ZapmizerRelinkResult =
  | { status: 'online' }
  | { status: 'starting' }
  | { status: 'needs_client'; url: string; expires_at: string | null }
  | { status: 'refused'; code: string; message: string };
