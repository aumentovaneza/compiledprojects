<template>
  <b-col cols="8">
    <b-card>
      <b-card-text><h5>View, Create or Edit {{name_plural}}</h5>To create a {{name_singular}}, just add the details or click Clear Form. To update an existing {{name_singular}}, please select from the list on the left.</b-card-text>
      <b-card-text>
        <ValidationObserver v-slot="{ handleSubmit,reset}">
          <div v-for="field in form_fields">
            <!-- <HiddenField v-if="field.type == 'Hidden'" :field="field" :data_select="selected_record" :hidden_data.sync="selected_record[field.field_key]"></HiddenField> -->
            <!-- <validation-provider v-if="field.required == 'true'" rules="required" v-slot="{ errors }">
              <span style="font-size:10px;color:red;font-style: italic;">{{ errors[0] }}</span> -->
              <TextField v-if="field.type == 'Text'" :field="field" :data_select="selected_record" :text_data.sync="selected_record[field.field_key]"></TextField>
            <!-- </validation-provider> -->
            <Textarea v-if="field.type == 'Textarea'" :field="field" :data_select="selected_record" :textarea_data.sync="selected_record[field.field_key]"></Textarea>
            <DateField v-if="field.type == 'Date'" :field="field" :data_select="selected_record" :date_data.sync="selected_record[field.field_key]"></DateField>
            <DropdownField v-if="field.type == 'Dropdown'" :field="field" :data_select="selected_record" :dropdown_data.sync="selected_record[field.field_key]"></DropdownField>
            <EmailField v-if="field.type == 'Email'" :field="field" :data_select="selected_record" :email_data.sync="selected_record[field.field_key]"></EmailField>
            <NumberField v-if="field.type == 'Number'" :field="field" :data_select="selected_record" :number_data.sync="selected_record[field.field_key]"></NumberField>
            <PercentField v-if="field.type == 'Percent'" :field="field" :data_select="selected_record" :percent_data.sync="selected_record[field.field_key]"></PercentField>
            <CurrencyField v-if="field.type == 'Currency'" :field="field" :data_select="selected_record" :currency_data.sync="selected_record[field.field_key]"></CurrencyField>
          </div>
          <b-button type="submit" variant="primary" v-if="selected_record.id" @click.preventDefault="handleSubmit(saveForm)">Update</b-button>
          <b-button type="submit" variant="primary" v-else @click.preventDefault="handleSubmit(saveForm)">Create</b-button>
          <b-button type="submit" variant="success" @click.preventDefault="reset(clearForm)" >Refresh Form</b-button>
        </ValidationObserver>
        <b-button type="submit" variant="secondary" @click.preventDefault="clearForm" >Clear Form</b-button>
        <DeleteButtonComponent :disabled="selected_record.id"  @deleteConfirmed="deleteConfirmed" @deleteDeclined="deleteDeclined" :name="this.name_singular"></DeleteButtonComponent>
      </b-card-text>
    </b-card>
  </b-col>
</template>
<script>
import moment from 'moment'
import DeleteButtonComponent from '../components/buttons/DeleteButtonComponent.vue'
import TextField from '../components/fields/TextField.vue'
import Textarea from '../components/fields/Textarea.vue'
import DateField from '../components/fields/DateField.vue'
import DropdownField from '../components/fields/DropdownField.vue'
import EmailField from '../components/fields/EmailField.vue'
import NumberField from '../components/fields/NumberField.vue'
import PercentField from '../components/fields/PercentField.vue'
import CurrencyField from '../components/fields/CurrencyField.vue'
import HiddenField from '../components/fields/HiddenField.vue'
import { ValidationProvider, ValidationObserver, extend } from 'vee-validate';
import { required } from 'vee-validate/dist/rules';

var qs = require('qs');
extend('required', {
  ...required,
  message: 'This field is required'
});

export default {
  name: 'ManageDataComponent',
  components: {
    DeleteButtonComponent,TextField,Textarea,DateField,DropdownField,EmailField,NumberField,HiddenField,ValidationObserver,ValidationProvider
  },
  props: [
    'data_select','name_singular','name_plural','form_fields','api_url'
  ],
  data: () =>{
    return{
      selected_record: {
        id: null,
      },
      hidden_data: null,
      text_data: null,
      textarea_data: null,
      date_data: null,
      dropdown_data: null,
      email_data: null,
      number_data: null,
      percent_data: null,
      currency_data: null,
    }
  },
  mounted() {
	  this.clearForm()
	  this.$emit('getList')
  },
  methods: {
    clearForm(e) {
			this.selected_record = {}
		},
		saveForm( e ) {
			this.$http.post(this.api_url, this.selected_record).then((r) => {
				this.$emit('getList')
				this.clearForm()
			})
		},
    deleteConfirmed: function() {
    		this.isLoading = true;
    		this.$http.get(this.api_url+"/"+encodeURI(this.selected_record.id) + '/delete').then((r) => {
        this.$emit('getList')
        this.clearForm()
      });
  	},
    deleteDeclined: function() {
      console.log("Delete Declined");
  	},
    getList: function() {
      this.$emit('getList')
    },
  },
  watch: {
    data_select(newVal) {
        this.selected_record = newVal
    }
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
	}
}
</script>
