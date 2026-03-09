<template>
  <div class="register-form">
    <div class="form-header">
      <h2>{{ pendingVerification ? '验证邮箱激活账号' : '创建账号' }}</h2>
      <p v-if="pendingVerification">
        验证码已发送至 {{ pendingEmail }}，输入验证码后即可激活并登录
      </p>
      <p v-else>使用 QQ 邮箱注册，系统会自动使用 QQ 号作为用户名</p>
    </div>

    <form
      v-if="!pendingVerification"
      :class="['auth-form', { 'has-error': !!error }]"
      @submit.prevent="handleRegister"
    >
      <div class="form-group">
        <label for="qqNumber">QQ号</label>
        <div class="input-wrapper">
          <input
            id="qqNumber"
            v-model="form.email"
            :class="{ 'input-error': error }"
            placeholder="例如：123456789（系统自动补全@qq.com）"
            required
            type="text"
            @input="error = ''"
          >
        </div>
      </div>

      <div class="form-group">
        <label for="password">密码</label>
        <div class="input-wrapper">
          <input
            id="password"
            v-model="form.password"
            :class="{ 'input-error': error }"
            :type="showPassword ? 'text' : 'password'"
            placeholder="至少6位"
            required
            @input="error = ''"
          >
          <button class="password-toggle" type="button" @click="showPassword = !showPassword">
            {{ showPassword ? '隐藏' : '显示' }}
          </button>
        </div>
      </div>

      <div class="form-group">
        <label for="confirmPassword">确认密码</label>
        <div class="input-wrapper">
          <input
            id="confirmPassword"
            v-model="form.confirmPassword"
            :class="{ 'input-error': error }"
            :type="showConfirmPassword ? 'text' : 'password'"
            placeholder="请再次输入密码"
            required
            @input="error = ''"
          >
          <button
            class="password-toggle"
            type="button"
            @click="showConfirmPassword = !showConfirmPassword"
          >
            {{ showConfirmPassword ? '隐藏' : '显示' }}
          </button>
        </div>
      </div>

      <div v-if="info" class="info-container">
        <span class="info-message">{{ info }}</span>
      </div>

      <div v-if="error" class="error-container">
        <span class="error-message">{{ error }}</span>
      </div>

      <button :disabled="loading" class="submit-btn" type="submit">
        <span v-if="loading">注册中...</span>
        <span v-else>注册</span>
      </button>
    </form>

    <form v-else :class="['auth-form', { 'has-error': !!error }]" @submit.prevent="handleVerify">
      <div v-if="!verificationSent" class="pending-tip">
        验证码发送失败，请点击下方“重发验证码”，或联系管理员手动激活账号。
      </div>

      <div class="form-group">
        <label for="verificationCode">邮箱验证码</label>
        <div class="input-wrapper">
          <input
            id="verificationCode"
            v-model="form.verificationCode"
            :class="{ 'input-error': error }"
            maxlength="6"
            placeholder="请输入6位数字验证码"
            required
            type="text"
            @input="error = ''"
          >
        </div>
      </div>

      <div v-if="info" class="info-container">
        <span class="info-message">{{ info }}</span>
      </div>

      <div v-if="error" class="error-container">
        <span class="error-message">{{ error }}</span>
      </div>

      <button :disabled="verifying" class="submit-btn" type="submit">
        <span v-if="verifying">验证中...</span>
        <span v-else>验证并登录</span>
      </button>

      <button
        :disabled="resending || resendCooldown > 0"
        class="resend-btn"
        type="button"
        @click="handleResend"
      >
        <span v-if="resending">发送中...</span>
        <span v-else-if="resendCooldown > 0">{{ resendCooldown }} 秒后可重发</span>
        <span v-else>重发验证码</span>
      </button>
    </form>

    <div class="form-footer">
      <p class="help-text">已有账号？<NuxtLink to="/login">去登录</NuxtLink></p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { useAuth } from '~/composables/useAuth'

const auth = useAuth()
const route = useRoute()

const form = ref({
  email: '',
  password: '',
  confirmPassword: '',
  verificationCode: ''
})

const loading = ref(false)
const verifying = ref(false)
const resending = ref(false)
const error = ref('')
const info = ref('')
const showPassword = ref(false)
const showConfirmPassword = ref(false)
const pendingVerification = ref(false)
const pendingEmail = ref('')
const verificationSent = ref(false)
const resendCooldown = ref(0)

let cooldownTimer: ReturnType<typeof setInterval> | null = null

const qqNumberRegex = /^[1-9]\d{4,10}$/

const normalizeQQNumberInput = (value: string): string =>
  value.trim().toLowerCase().replace(/@qq\.com$/i, '')

const toQQEmail = (value: string): string => `${normalizeQQNumberInput(value)}@qq.com`

const stopCooldownTimer = () => {
  if (cooldownTimer) {
    clearInterval(cooldownTimer)
    cooldownTimer = null
  }
}

const startCooldown = (seconds: number) => {
  stopCooldownTimer()
  resendCooldown.value = seconds
  cooldownTimer = setInterval(() => {
    if (resendCooldown.value <= 1) {
      resendCooldown.value = 0
      stopCooldownTimer()
      return
    }
    resendCooldown.value -= 1
  }, 1000)
}

onBeforeUnmount(() => {
  stopCooldownTimer()
})

const goAfterLogin = async () => {
  await auth.initAuth()
  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
  await navigateTo(redirect)
}

const handleRegister = async () => {
  if (!form.value.email || !form.value.password) {
    error.value = '请完整填写注册信息'
    return
  }

  const qqNumber = normalizeQQNumberInput(form.value.email)
  if (!qqNumberRegex.test(qqNumber)) {
    error.value = '请输入正确的QQ号'
    return
  }

  if (form.value.password.length < 6) {
    error.value = '密码长度不能少于6位'
    return
  }

  if (form.value.password !== form.value.confirmPassword) {
    error.value = '两次输入的密码不一致'
    return
  }

  loading.value = true
  error.value = ''
  info.value = ''

  try {
    const response: any = await $fetch('/api/auth/register', {
      method: 'POST',
      body: {
        qqNumber,
        password: form.value.password
      }
    })

    if (response?.requiresEmailVerification) {
      pendingVerification.value = true
      pendingEmail.value = response.email || toQQEmail(form.value.email)
      verificationSent.value = !!response.verificationSent
      form.value.verificationCode = ''
      if (verificationSent.value) {
        startCooldown(60)
      }
      info.value = response.message || '请完成邮箱验证码验证后登录'
      return
    }

    await goAfterLogin()
  } catch (err: any) {
    error.value = err?.data?.message || err?.message || '注册失败，请稍后重试'
  } finally {
    loading.value = false
  }
}

const handleVerify = async () => {
  if (!/^\d{6}$/.test(form.value.verificationCode.trim())) {
    error.value = '请输入6位数字验证码'
    return
  }

  verifying.value = true
  error.value = ''
  info.value = ''

  try {
    await $fetch('/api/auth/register/verify', {
      method: 'POST',
      body: {
        email: pendingEmail.value,
        code: form.value.verificationCode.trim()
      }
    })

    await goAfterLogin()
  } catch (err: any) {
    error.value = err?.data?.message || err?.message || '验证失败，请重试'
  } finally {
    verifying.value = false
  }
}

const handleResend = async () => {
  if (!pendingEmail.value || resendCooldown.value > 0) {
    return
  }

  resending.value = true
  error.value = ''
  info.value = ''

  try {
    await $fetch('/api/auth/register/resend-code', {
      method: 'POST',
      body: {
        email: pendingEmail.value
      }
    })

    verificationSent.value = true
    startCooldown(60)
    info.value = '验证码已发送，请查收邮箱'
  } catch (err: any) {
    error.value = err?.data?.message || err?.message || '重发验证码失败，请稍后重试'
  } finally {
    resending.value = false
  }
}
</script>

<style scoped>
.register-form {
  width: 100%;
  max-width: 420px;
}

.form-header {
  text-align: center;
  margin-bottom: 24px;
}

.form-header h2 {
  color: var(--text-primary);
  font-size: 28px;
  margin: 0 0 8px;
  font-weight: 700;
}

.form-header p {
  color: var(--text-secondary);
  margin: 0;
  font-size: 14px;
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.form-group label {
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 600;
}

.input-wrapper {
  position: relative;
}

.input-wrapper input {
  width: 100%;
  padding: 12px 14px;
  background: var(--bg-tertiary);
  border: 1px solid var(--border-secondary);
  border-radius: 10px;
  color: var(--text-primary);
  font-size: 14px;
  transition: border-color 0.2s;
}

.input-wrapper input:focus {
  outline: none;
  border-color: var(--accent-primary, #4f8cff);
}

.input-wrapper input.input-error {
  border-color: #ef4444;
}

.password-toggle {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  border: none;
  background: transparent;
  color: var(--text-secondary);
  font-size: 12px;
  cursor: pointer;
  padding: 2px 4px;
}

.pending-tip {
  background: rgba(59, 130, 246, 0.12);
  border: 1px solid rgba(59, 130, 246, 0.25);
  border-radius: 10px;
  color: #93c5fd;
  font-size: 12px;
  line-height: 1.6;
  padding: 10px 12px;
}

.info-container {
  background: rgba(59, 130, 246, 0.1);
  border: 1px solid rgba(59, 130, 246, 0.3);
  border-radius: 10px;
  padding: 10px 12px;
}

.info-message {
  color: #60a5fa;
  font-size: 13px;
}

.error-container {
  background: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  border-radius: 10px;
  padding: 10px 12px;
}

.error-message {
  color: #ef4444;
  font-size: 13px;
}

.submit-btn,
.resend-btn {
  width: 100%;
  padding: 12px 14px;
  border: none;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}

.submit-btn {
  background: #2563eb;
  color: #fff;
}

.resend-btn {
  background: transparent;
  border: 1px solid var(--border-secondary);
  color: var(--text-secondary);
}

.submit-btn:disabled,
.resend-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.form-footer {
  margin-top: 18px;
  text-align: center;
}

.help-text {
  font-size: 13px;
  color: var(--text-secondary);
}

.help-text a {
  color: #60a5fa;
  text-decoration: none;
  font-weight: 600;
}

.help-text a:hover {
  text-decoration: underline;
}
</style>
