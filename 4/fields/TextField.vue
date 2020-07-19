<template>

    <b-form-group
      :id="field.key"
      label-cols-sm="4"
      label-cols-lg="3"
      :description="field.field_description"
      :label="field.label"
      :label-for="field.field_key"
      >
      <template v-if="field.required == 'true'">
        <validation-provider  rules="required" v-slot="{ errors }">
          <span style="font-size:10px;color:red;font-style: italic;">{{ errors[0] }}</span>
          <b-form-input :id="field.field_key" v-model="textdata_assign" @change="$emit('update:text_data', text);"></b-form-input>
        </validation-provider>
      </template>

      <template v-else>
        <b-form-input :id="field.field_key" v-model="textdata_assign" @change="$emit('update:text_data', text);"></b-form-input>
      </template>
    </b-form-group>

</template>
<script>
import moment from 'moment'

var qs = require('qs');
import { ValidationProvider, extend } from 'vee-validate';
import { required } from 'vee-validate/dist/rules';
extend('required', {
  ...required,
  message: 'This field is required'
});
export default {
  name: 'TextField',
  components: {
  ValidationProvider
  },
  props: [
    'field','data_select','text_data'
  ],
  mounted () {
  },
  data: () => {
    return {
      selected_record: {},
      key: '',
      text: '',
    }
  },
  watch: {
    data_select(newVal) {
        this.selected_record = newVal
    },
  },
  computed: {
    data_assign: {
      get() {
        return this.data_select
      },
      set (value) {
        this.selected_record = value
      }
    },
    key_assign: {
      get() {
        return this.field.field_key
      },
      set (value) {
        this.key = value
      }
    },
    textdata_assign: {
      get() {
        return this.text_data
      },
      set (value) {
        this.text = value
      }
    }
  }
}
</script>
